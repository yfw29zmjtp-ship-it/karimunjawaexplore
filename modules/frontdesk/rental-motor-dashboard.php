<?php

/**
 * Rental Motor Dashboard — Enhanced monitoring with elegant UI
 * Track rented motors, available units, revenue, and status
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$businessId  = $_SESSION['business_id'] ?? 1;

// Auto-update overdue status
$normalizeStatus = $pdo->prepare("UPDATE rental_motor_bookings SET status='active'
    WHERE business_id=? AND status='overdue'
    AND end_datetime > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$normalizeStatus->execute([$businessId]);

$markOverdue = $pdo->prepare("UPDATE rental_motor_bookings SET status='overdue'
    WHERE business_id=? AND status='active'
    AND end_datetime <= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$markOverdue->execute([$businessId]);

// ── Fetch Statistics ──────────────────────────────────────────────────────────
// Total motors by status
$motorStats = $pdo->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status='rented' THEN 1 ELSE 0 END) as rented,
    SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END) as maintenance
    FROM rental_motors WHERE business_id=?");
$motorStats->execute([$businessId]);
$motorData = $motorStats->fetch(PDO::FETCH_ASSOC);

$totalMotors = (int)$motorData['total'];
$availableCount = (int)$motorData['available'];
$rentedCount = (int)$motorData['rented'];
$maintenanceCount = (int)$motorData['maintenance'];
$occupancyRate = $totalMotors > 0 ? round(($rentedCount / $totalMotors) * 100, 1) : 0;

// Active & Overdue Rentals
$activeRentals = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings 
    WHERE business_id=? AND status IN ('active','overdue')");
$activeRentals->execute([$businessId]);
$activeCount = (int)$activeRentals->fetchColumn();

$overdueRentals = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings 
    WHERE business_id=?
    AND status IN ('active','overdue')
    AND end_datetime <= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$overdueRentals->execute([$businessId]);
$overdueCount = (int)$overdueRentals->fetchColumn();

// Monthly Revenue
$currentMonth = date('Y-m');
$revenueStat = $pdo->prepare("SELECT 
    COALESCE(SUM(total_price),0) as revenue,
    COUNT(*) as rentals_count
    FROM rental_motor_bookings 
    WHERE business_id=? AND status IN ('active','returned','overdue')
    AND DATE_FORMAT(created_at,'%Y-%m')=?");
$revenueStat->execute([$businessId, $currentMonth]);
$revData = $revenueStat->fetch(PDO::FETCH_ASSOC);

// Currently Rented Motors (with guest info)
$rented = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name, rm.color, rm.daily_rate
    FROM rental_motor_bookings rb
    JOIN rental_motors rm ON rb.motor_id = rm.id
    WHERE rb.business_id=? AND rb.status IN ('active','overdue')
    ORDER BY rb.status DESC, rb.end_datetime ASC");
$rented->execute([$businessId]);
$rentedList = $rented->fetchAll(PDO::FETCH_ASSOC);

// Ready/Available Motors
$available = $pdo->prepare("SELECT * FROM rental_motors 
    WHERE business_id=? AND status='available'
    ORDER BY motor_name ASC");
$available->execute([$businessId]);
$availableList = $available->fetchAll(PDO::FETCH_ASSOC);

// All motors + current renter info (for the color-coded container grid)
$allMotorsStmt = $pdo->prepare("SELECT
        rm.id AS motor_id, rm.plate_number, rm.motor_name, rm.color AS motor_color, rm.daily_rate, rm.status AS motor_status,
        rm.partner_owner, rm.owner_phone, rm.owner_commission_pct,
        rb.id AS booking_id, rb.guest_name, rb.room_number, rb.start_datetime, rb.end_datetime,
        rb.total_price, rb.status AS booking_status
    FROM rental_motors rm
    LEFT JOIN rental_motor_bookings rb ON rb.motor_id = rm.id AND rb.status IN ('active','overdue')
    WHERE rm.business_id=?
    ORDER BY rm.motor_name ASC");
$allMotorsStmt->execute([$businessId]);
$allMotorsList = $allMotorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Split into hotel-owned and partner-owned
$hotelMotors  = array_filter($allMotorsList, fn($m) => empty($m['partner_owner']));
$mitraMotors  = array_filter($allMotorsList, fn($m) => !empty($m['partner_owner']));

// Count active partner motors
$mitraActiveCount = count(array_filter($mitraMotors, fn($m) => $m['booking_id']));
$hotelActiveCount = count(array_filter($hotelMotors, fn($m) => $m['booking_id']));

// Recent Returned Motors
$recent = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name
    FROM rental_motor_bookings rb
    JOIN rental_motors rm ON rb.motor_id = rm.id
    WHERE rb.business_id=? AND rb.status='returned'
    ORDER BY rb.actual_return DESC LIMIT 10");
$recent->execute([$businessId]);
$recentReturns = $recent->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<style>
    .dashboard-page {
        padding: 1rem 1.15rem 1.25rem;
        max-width: 1400px;
        margin: 0 auto;
    }

    .dashboard-header {
        margin-bottom: 1rem;
    }

    .dashboard-header h1 {
        margin: 0 0 0.2rem;
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--text-primary);
    }

    .dashboard-header .subtitle {
        font-size: 0.78rem;
        color: var(--text-secondary);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 0.85rem 0.9rem 0.8rem;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.07);
        border-top: 3px solid var(--stat-color);
    }

    .stat-card .label {
        font-size: 0.72rem;
        color: var(--text-secondary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.3rem;
    }

    .stat-card .value {
        font-size: 1.5rem;
        font-weight: 900;
        color: var(--stat-color);
        line-height: 1;
        margin-bottom: 0.25rem;
    }

    .stat-card .detail {
        font-size: 0.7rem;
        color: var(--text-secondary);
    }

    .stat-card .progress-bar {
        height: 5px;
        background: #e2e8f0;
        border-radius: 3px;
        margin-top: 0.6rem;
        overflow: hidden;
    }

    .stat-card .progress-fill {
        height: 100%;
        background: var(--stat-color);
        border-radius: 3px;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 1rem 0 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .section-title .icon {
        font-size: 1.3rem;
    }

    .rented-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .rental-card {
        background: white;
        border-radius: 10px;
        padding: 0.95rem 1rem;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.07);
        border-left: 4px solid var(--card-color);
    }

    .rental-card.overdue {
        border-left-color: #ef4444;
        background: #fef2f2;
    }

    .rental-card.active {
        border-left-color: #10b981;
    }

    .rc-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 0.55rem;
    }

    .rc-plate {
        font-size: 0.98rem;
        font-weight: 800;
        color: #1e293b;
        font-family: 'Courier New', monospace;
    }

    .rc-status {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 20px;
        font-size: 0.65rem;
        font-weight: 700;
        color: white;
    }

    .rc-status.active {
        background: #10b981;
    }

    .rc-status.overdue {
        background: #ef4444;
    }

    .rc-info {
        font-size: 0.8rem;
        margin-bottom: 0.45rem;
    }

    .rc-info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.25rem;
    }

    .rc-info-label {
        color: var(--text-secondary);
        font-weight: 500;
    }

    .rc-info-value {
        font-weight: 600;
        color: var(--text-primary);
    }

    .rc-timeline {
        font-size: 0.75rem;
        background: #f8fafc;
        border-radius: 8px;
        padding: 0.6rem 0.7rem;
        margin: 0.55rem 0;
    }

    .rc-time {
        color: var(--text-secondary);
        margin-bottom: 0.3rem;
    }

    .rc-time strong {
        color: var(--text-primary);
    }

    .rc-price {
        background: #f0f4ff;
        border-radius: 8px;
        padding: 0.55rem 0.7rem;
        margin: 0.55rem 0;
        border-left: 3px solid #6366f1;
    }

    .rc-price .amount {
        font-size: 1rem;
        font-weight: 800;
        color: #6366f1;
    }

    .rc-price .note {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin-top: 0.2rem;
    }

    .available-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 0.65rem;
    }

    .motor-available {
        background: linear-gradient(135deg, #dcfce7, #d1fae5);
        border-radius: 10px;
        padding: 0.8rem 0.75rem;
        border: 1px solid #10b981;
        text-align: center;
    }

    .motor-available .icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .motor-available .name {
        font-weight: 800;
        color: #047857;
        margin-bottom: 0.25rem;
        font-size: 0.82rem;
    }

    .motor-available .plate {
        font-size: 0.82rem;
        font-family: 'Courier New';
        font-weight: 700;
        color: #065f46;
    }

    .motor-available .rate {
        font-size: 0.72rem;
        color: #047857;
        margin-top: 0.5rem;
    }

    .motor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
        gap: 0.6rem;
    }

    .motor-box {
        border-radius: 10px;
        padding: 0.75rem 0.6rem;
        text-align: center;
        cursor: pointer;
        border: 1px solid transparent;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .motor-box:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    .motor-box .mb-icon {
        font-size: 1.6rem;
        margin-bottom: 0.3rem;
    }

    .motor-box .mb-plate {
        font-family: 'Courier New', monospace;
        font-weight: 800;
        font-size: 0.8rem;
    }

    .motor-box .mb-name {
        font-size: 0.7rem;
        font-weight: 600;
        margin-top: 0.15rem;
        opacity: 0.9;
    }

    .motor-box .mb-guest {
        font-size: 0.68rem;
        font-weight: 700;
        margin-top: 0.4rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .motor-box .mb-badge {
        display: inline-block;
        margin-top: 0.3rem;
        font-size: 0.6rem;
        font-weight: 800;
        background: rgba(255, 255, 255, 0.7);
        padding: 0.1rem 0.4rem;
        border-radius: 20px;
    }

    .motor-box.available {
        background: linear-gradient(135deg, #dcfce7, #d1fae5);
        border-color: #10b981;
        color: #047857;
    }

    .motor-box.rented {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        border-color: #ef4444;
        color: #b91c1c;
    }

    .motor-box.rented.overdue {
        animation: motorPulse 1.6s ease-in-out infinite;
    }

    @keyframes motorPulse {

        0%,
        100% {
            box-shadow: 0 0 0 rgba(239, 68, 68, 0.35);
        }

        50% {
            box-shadow: 0 0 0 6px rgba(239, 68, 68, 0.12);
        }
    }

    .motor-box.maintenance {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border-color: #f59e0b;
        color: #92400e;
    }

    .md-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        z-index: 999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .md-overlay.open {
        display: flex;
    }

    .md-box {
        background: white;
        border-radius: 14px;
        max-width: 380px;
        width: 100%;
        padding: 1.1rem 1.2rem 1.3rem;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    }

    .md-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.7rem;
    }

    .md-header h3 {
        margin: 0;
        font-size: 1rem;
    }

    .md-header button {
        border: none;
        background: #f1f5f9;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 0.9rem;
    }

    .md-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        padding: 0.4rem 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .md-row span:first-child {
        color: var(--text-secondary);
    }

    .md-row span:last-child {
        font-weight: 700;
        color: var(--text-primary);
        text-align: right;
    }

    .md-footer {
        margin-top: 0.8rem;
        text-align: center;
    }

    .dashboard-content {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.9rem;
        align-items: start;
        margin-bottom: 0.9rem;
    }

    .dashboard-panel {
        background: rgba(255, 255, 255, 0.68);
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 14px;
        padding: 0.9rem;
        box-shadow: 0 1px 10px rgba(15, 23, 42, 0.04);
        backdrop-filter: blur(6px);
    }

    .panel-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.7rem;
    }

    .panel-head h2 {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 800;
        color: var(--text-primary);
    }

    .panel-head .hint {
        font-size: 0.72rem;
        color: var(--text-secondary);
    }

    .panel-stack {
        display: grid;
        gap: 0.8rem;
    }

    .recent-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .recent-table th {
        background: #f8fafc;
        padding: 1rem;
        text-align: left;
        font-weight: 700;
        font-size: 0.82rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #e2e8f0;
    }

    .recent-table td {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .recent-table tr:last-child td {
        border-bottom: none;
    }

    .recent-table tr:hover {
        background: #fafbff;
    }

    .badge {
        display: inline-block;
        padding: 0.3rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        color: white;
    }

    .badge-success {
        background: #10b981;
    }

    .badge-warning {
        background: #f59e0b;
    }

    .badge-danger {
        background: #ef4444;
    }

    .badge-secondary {
        background: #64748b;
    }

    .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: var(--text-secondary);
    }

    .empty-state .icon {
        font-size: 2.5rem;
        margin-bottom: 0.75rem;
    }

    .empty-state .text {
        font-size: 0.95rem;
    }

    .action-link {
        color: #6366f1;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.85rem;
    }

    .action-link:hover {
        text-decoration: underline;
    }

    @media(max-width: 768px) {
        .dashboard-content {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .rented-grid {
            grid-template-columns: 1fr;
        }

        .available-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<div class="dashboard-page">
    <!-- Header -->
    <div class="dashboard-header" style="display:flex;justify-content:space-between;align-items:center">
        <div>
            <h1>🏍️ Dashboard Rental Motor</h1>
            <div class="subtitle">Monitoring armada dan penyewaan real-time</div>
        </div>
        <a href="rental-motor.php?view=manage" class="btn-rm btn-rm-primary" style="white-space:nowrap;text-decoration:none;padding:0.5rem 1rem">⚙️ Kelola Armada</a>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card" style="--stat-color:#6366f1">
            <div class="label">Total Motor</div>
            <div class="value"><?php echo $totalMotors; ?></div>
            <div class="detail">dalam sistem</div>
        </div>

        <div class="stat-card" style="--stat-color:#10b981">
            <div class="label">Siap Disewa</div>
            <div class="value"><?php echo $availableCount; ?></div>
            <div class="detail">tersedia sekarang</div>
            <?php if ($totalMotors > 0): ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo ($availableCount / $totalMotors) * 100; ?>%"></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card" style="--stat-color:#f59e0b">
            <div class="label">Sedang Disewa</div>
            <div class="value"><?php echo $rentedCount; ?></div>
            <div class="detail"><?php echo $occupancyRate; ?>% okupansi</div>
            <?php if ($totalMotors > 0): ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo ($rentedCount / $totalMotors) * 100; ?>%"></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card" style="--stat-color:#ef4444">
            <div class="label">Aktif Sekarang</div>
            <div class="value"><?php echo $activeCount; ?></div>
            <div class="detail"><?php echo $overdueCount > 0 ? $overdueCount . ' overdue' : 'all on time'; ?></div>
        </div>

        <div class="stat-card" style="--stat-color:#8b5cf6">
            <div class="label">Revenue Bulan Ini</div>
            <div class="value">Rp <?php echo number_format($revData['revenue'], 0, ',', '.'); ?></div>
            <div class="detail"><?php echo $revData['rentals_count']; ?> transaksi</div>
        </div>

        <div class="stat-card" style="--stat-color:#06b6d4">
            <div class="label">Maintenance</div>
            <div class="value"><?php echo $maintenanceCount; ?></div>
            <div class="detail">dalam perbaikan</div>
        </div>
    </div>

    <div class="dashboard-content">
        <?php
        // Reusable motor grid renderer
        function renderMotorGrid(array $list): void
        { ?>
            <?php if (!empty($list)): ?>
                <div class="motor-grid">
                    <?php foreach ($list as $m):
                        $state    = $m['motor_status'];
                        $isRented = $state === 'rented' && $m['booking_id'];
                        $endTs = !empty($m['end_datetime']) ? strtotime((string)$m['end_datetime']) : false;
                        $isOverdue = $isRented && $endTs !== false && $endTs <= (time() - 86400);
                        $boxClass = $isRented ? 'rented' . ($isOverdue ? ' overdue' : '') : $state;
                        $detail   = [
                            'plate'          => $m['plate_number'],
                            'name'           => $m['motor_name'],
                            'color'          => $m['motor_color'],
                            'rate'           => (float)$m['daily_rate'],
                            'status'         => $state,
                            'booking_status' => $m['booking_status'],
                            'guest'          => $m['guest_name'],
                            'room'           => $m['room_number'],
                            'start'          => $m['start_datetime'],
                            'end'            => $m['end_datetime'],
                            'is_overdue_24h' => $isOverdue ? 1 : 0,
                            'total'          => (float)$m['total_price'],
                            'partner_owner'  => $m['partner_owner'] ?? '',
                            'owner_phone'    => $m['owner_phone'] ?? '',
                        ];
                    ?>
                        <div class="motor-box <?php echo $boxClass; ?>"
                            onclick="showMotorDetail(this)"
                            data-motor='<?php echo htmlspecialchars(json_encode($detail), ENT_QUOTES); ?>'>
                            <div class="mb-icon">🏍️</div>
                            <div class="mb-plate"><?php echo htmlspecialchars($m['plate_number']); ?></div>
                            <div class="mb-name"><?php echo htmlspecialchars($m['motor_name']); ?></div>
                            <?php if ($isRented): ?>
                                <div class="mb-guest"><?php echo htmlspecialchars(mb_strimwidth((string)$m['guest_name'], 0, 16, '…')); ?></div>
                                <?php if ($isOverdue): ?><div class="mb-badge">⚠ Overdue</div><?php endif; ?>
                            <?php elseif ($state === 'maintenance'): ?>
                                <div class="mb-guest">🔧 Maintenance</div>
                            <?php else: ?>
                                <div class="mb-guest">Tersedia</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" style="padding:1rem 0 0.25rem">
                    <div class="icon" style="font-size:1.5rem">🏍️</div>
                    <div class="text" style="font-size:0.8rem">Belum ada motor</div>
                </div>
            <?php endif; ?>
        <?php } ?>

        <!-- Motor Hotel -->
        <div class="dashboard-panel">
            <div class="panel-head">
                <h2>🏨 Motor Hotel</h2>
                <div class="hint"><?php echo $hotelActiveCount; ?> / <?php echo count($hotelMotors); ?> aktif</div>
            </div>
            <?php renderMotorGrid(array_values($hotelMotors)); ?>
        </div>

        <!-- Motor Mitra -->
        <div class="dashboard-panel" style="border-top:3px solid #10b981">
            <div class="panel-head">
                <h2>🤝 Motor Mitra</h2>
                <div class="hint"><?php echo $mitraActiveCount; ?> / <?php echo count($mitraMotors); ?> aktif</div>
            </div>
            <?php if (!empty($mitraMotors)): ?>
                <?php
                // Group by partner_owner
                $grouped = [];
                foreach ($mitraMotors as $m) {
                    $key = $m['partner_owner'];
                    if (!isset($grouped[$key])) $grouped[$key] = ['owner' => $m['partner_owner'], 'phone' => $m['owner_phone'], 'pct' => $m['owner_commission_pct'], 'motors' => []];
                    $grouped[$key]['motors'][] = $m;
                }
                foreach ($grouped as $g): ?>
                    <div style="margin-bottom:1rem">
                        <div style="padding:0.45rem 0.75rem;background:#f0fdf4;border-radius:6px;font-size:0.82rem;font-weight:700;color:#15803d;margin-bottom:0.5rem;display:flex;align-items:center;gap:0.5rem">
                            🤝 <?php echo htmlspecialchars($g['owner']); ?>
                            <?php if ($g['phone']): ?>
                                <span style="font-weight:400;color:#6b7280;font-size:0.75rem">· <?php echo htmlspecialchars($g['phone']); ?></span>
                            <?php endif; ?>
                            <?php if ($g['pct'] > 0): ?>
                                <span style="margin-left:auto;font-size:0.73rem;background:#dcfce7;color:#15803d;padding:0.1rem 0.45rem;border-radius:4px"><?php echo $g['pct']; ?>% komisi</span>
                            <?php endif; ?>
                        </div>
                        <?php renderMotorGrid($g['motors']); ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="padding:1rem 0 0.25rem">
                    <div class="icon" style="font-size:1.5rem">🤝</div>
                    <div class="text" style="font-size:0.8rem">Belum ada motor mitra</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-panel">
            <div class="panel-head">
                <h2>📊 Rekapan Sewa</h2>
                <div class="hint">10 transaksi terbaru</div>
            </div>

            <?php if (!empty($recentReturns)): ?>
                <div style="overflow-x:auto">
                    <table class="recent-table" style="box-shadow:none;border-radius:10px">
                        <thead>
                            <tr>
                                <th style="padding:0.6rem 0.7rem">Motor</th>
                                <th style="padding:0.6rem 0.7rem">Tamu</th>
                                <th style="padding:0.6rem 0.7rem">Kembali</th>
                                <th style="padding:0.6rem 0.7rem">Total</th>
                                <th style="padding:0.6rem 0.7rem">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentReturns as $ret): ?>
                                <tr>
                                    <td style="padding:0.6rem 0.7rem">
                                        <strong><?php echo htmlspecialchars($ret['plate_number']); ?></strong>
                                        <div style="font-size:0.72rem;color:var(--text-secondary)"><?php echo htmlspecialchars($ret['motor_name']); ?></div>
                                    </td>
                                    <td style="padding:0.6rem 0.7rem">
                                        <?php echo htmlspecialchars($ret['guest_name'] ?: '-'); ?>
                                        <?php if ($ret['room_number']): ?>
                                            <div style="font-size:0.72rem;color:var(--text-secondary)">Kamar #<?php echo htmlspecialchars($ret['room_number']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.7rem;font-size:0.8rem;color:var(--text-secondary)">
                                        <?php echo $ret['actual_return'] ? date('d M H:i', strtotime($ret['actual_return'])) : '-'; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.7rem;font-weight:700">Rp <?php echo number_format($ret['total_price'], 0, ',', '.'); ?></td>
                                    <td style="padding:0.6rem 0.7rem"><span class="badge badge-success">Kembali</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state" style="padding:1.25rem 0 0.5rem">
                    <div class="icon">📊</div>
                    <div class="text">Belum ada transaksi sewa</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Motor Detail Modal -->
<div id="motorDetailModal" class="md-overlay" onclick="if(event.target===this) closeMotorDetail()">
    <div class="md-box">
        <div class="md-header">
            <h3 id="mdTitle">🏍️ Detail Motor</h3>
            <button type="button" onclick="closeMotorDetail()">✕</button>
        </div>
        <div id="mdBody"></div>
        <div class="md-footer">
            <a href="rental-motor.php?view=manage" class="action-link">→ Kelola di Rental Motor</a>
        </div>
    </div>
</div>

<script>
    function showMotorDetail(el) {
        const d = JSON.parse(el.dataset.motor);
        document.getElementById('mdTitle').textContent = '🏍️ ' + d.plate;

        let html = '';
        html += `<div class="md-row"><span>Motor</span><span>${d.name}</span></div>`;
        if (d.color) html += `<div class="md-row"><span>Warna</span><span>${d.color}</span></div>`;
        html += `<div class="md-row"><span>Tarif</span><span>Rp ${Math.round(d.rate).toLocaleString('id-ID')}/hari</span></div>`;

        if (d.status === 'rented' && d.guest) {
            const isOverdue = String(d.is_overdue_24h || '0') === '1';
            html += `<div class="md-row"><span>Status</span><span style="color:${isOverdue ? '#ef4444' : '#10b981'}">${isOverdue ? '⚠ Overdue' : '✓ Aktif'}</span></div>`;
            html += `<div class="md-row"><span>Tamu</span><span>${d.guest}</span></div>`;
            if (d.room) html += `<div class="md-row"><span>Kamar</span><span>#${d.room}</span></div>`;
            if (d.start) html += `<div class="md-row"><span>Mulai</span><span>${fmtMotorDt(d.start)}</span></div>`;
            if (d.end) html += `<div class="md-row"><span>Kembali</span><span>${fmtMotorDt(d.end)}</span></div>`;
            html += `<div class="md-row"><span>Total</span><span>Rp ${Math.round(d.total).toLocaleString('id-ID')}</span></div>`;
        } else if (d.status === 'maintenance') {
            html += `<div class="md-row"><span>Status</span><span style="color:#f59e0b">🔧 Maintenance</span></div>`;
        } else {
            html += `<div class="md-row"><span>Status</span><span style="color:#10b981">✓ Siap Disewa</span></div>`;
        }

        document.getElementById('mdBody').innerHTML = html;
        document.getElementById('motorDetailModal').classList.add('open');
    }

    function closeMotorDetail() {
        document.getElementById('motorDetailModal').classList.remove('open');
    }

    function fmtMotorDt(s) {
        const dt = new Date(String(s).replace(' ', 'T'));
        if (isNaN(dt)) return s;
        return dt.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short'
        }) + ' ' + dt.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>