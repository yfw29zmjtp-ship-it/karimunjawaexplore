<?php

/**
 * Rental Mobil / Taxi Dashboard
 * Monitoring armada, rekap per pemilik/mitra, revenue analysis
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

$db         = Database::getInstance();
$pdo        = $db->getConnection();
$businessId = $_SESSION['business_id'] ?? 1;

// Auto-create tables if not exist (same as rental-mobil.php)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rental_cars (
        id INT AUTO_INCREMENT PRIMARY KEY, business_id INT NOT NULL DEFAULT 1,
        plate_number VARCHAR(20) NOT NULL, car_name VARCHAR(100) NOT NULL,
        car_type ENUM('sedan','mpv','minibus','pickup','suv','van','other') NOT NULL DEFAULT 'mpv',
        color VARCHAR(30) DEFAULT NULL, year SMALLINT DEFAULT NULL, capacity TINYINT DEFAULT NULL,
        daily_rate DECIMAL(15,2) NOT NULL DEFAULT 0, partner_owner VARCHAR(120) DEFAULT NULL,
        owner_phone VARCHAR(30) DEFAULT NULL, owner_commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        status ENUM('available','rented','maintenance') NOT NULL DEFAULT 'available',
        notes TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_biz (business_id), KEY idx_status (business_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rental_car_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY, business_id INT NOT NULL DEFAULT 1,
        car_id INT NOT NULL, invoice_id INT DEFAULT NULL, guest_name VARCHAR(120) NOT NULL,
        guest_phone VARCHAR(30) DEFAULT NULL, room_number VARCHAR(20) DEFAULT NULL,
        booking_id INT DEFAULT NULL, start_datetime DATETIME NOT NULL, end_datetime DATETIME NOT NULL,
        actual_return DATETIME DEFAULT NULL, daily_rate DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_price DECIMAL(15,2) NOT NULL DEFAULT 0, owner_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        hotel_commission DECIMAL(15,2) NOT NULL DEFAULT 0, deposit DECIMAL(15,2) NOT NULL DEFAULT 0,
        trip_destination VARCHAR(200) DEFAULT NULL,
        status ENUM('active','returned','overdue','cancelled') NOT NULL DEFAULT 'active',
        notes TEXT DEFAULT NULL, created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_biz (business_id), KEY idx_car (car_id), KEY idx_status (business_id, status),
        KEY idx_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) { /* ignore */
}

$pdo->exec("UPDATE rental_car_bookings SET status='overdue'
    WHERE status='active' AND end_datetime < NOW() AND business_id={$businessId}");

// ── Filter month/year ──────────────────────────────────────────────────────────
$filterYear  = (int)($_GET['year']  ?? date('Y'));
$filterMonth = (int)($_GET['month'] ?? date('n'));
$filterOwner = trim($_GET['owner'] ?? '');
$monthStart  = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
$monthEnd    = date('Y-m-t', strtotime($monthStart));

// ── Fleet stats ────────────────────────────────────────────────────────────────
$fleetStats = $pdo->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status='rented' THEN 1 ELSE 0 END) as rented,
    SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END) as maintenance
    FROM rental_cars WHERE business_id=?");
$fleetStats->execute([$businessId]);
$fleetData = $fleetStats->fetch(PDO::FETCH_ASSOC);

// ── Active/overdue rentals ─────────────────────────────────────────────────────
$activeStmt = $pdo->prepare("SELECT cb.*, rc.plate_number, rc.car_name, rc.car_type, rc.color,
    rc.partner_owner, rc.owner_commission_pct
    FROM rental_car_bookings cb
    JOIN rental_cars rc ON cb.car_id = rc.id
    WHERE cb.business_id=? AND cb.status IN ('active','overdue')
    ORDER BY cb.status DESC, cb.end_datetime ASC");
$activeStmt->execute([$businessId]);
$activeRentals = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Monthly revenue ────────────────────────────────────────────────────────────
$revStmt = $pdo->prepare("SELECT
    COALESCE(SUM(total_price),0) as total_revenue,
    COALESCE(SUM(owner_amount),0) as total_owner,
    COALESCE(SUM(hotel_commission),0) as total_hotel,
    COUNT(*) as total_trips
    FROM rental_car_bookings
    WHERE business_id=? AND status IN ('returned')
    AND DATE(actual_return) BETWEEN ? AND ?");
$revStmt->execute([$businessId, $monthStart, $monthEnd]);
$revData = $revStmt->fetch(PDO::FETCH_ASSOC);

// ── Available cars ─────────────────────────────────────────────────────────────
$availStmt = $pdo->prepare("SELECT * FROM rental_cars WHERE business_id=? AND status='available' ORDER BY car_name ASC");
$availStmt->execute([$businessId]);
$availableList = $availStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Owner Recap ────────────────────────────────────────────────────────────────
$ownerWhere  = "cb.business_id=? AND cb.status='returned' AND DATE(cb.actual_return) BETWEEN ? AND ?";
$ownerParams = [$businessId, $monthStart, $monthEnd];
if ($filterOwner) {
    $ownerWhere .= " AND rc.partner_owner LIKE ?";
    $ownerParams[] = "%{$filterOwner}%";
}

$ownerStmt = $pdo->prepare("SELECT
    rc.partner_owner, rc.owner_phone,
    COUNT(*)                      as total_trips,
    COALESCE(SUM(cb.total_price),0) as total_revenue,
    COALESCE(SUM(cb.owner_amount),0) as owner_total,
    COALESCE(SUM(cb.hotel_commission),0) as hotel_total,
    AVG(rc.owner_commission_pct)  as avg_comm_pct,
    GROUP_CONCAT(DISTINCT rc.car_name ORDER BY rc.car_name SEPARATOR ', ') as cars
    FROM rental_car_bookings cb
    JOIN rental_cars rc ON cb.car_id = rc.id
    WHERE {$ownerWhere}
    GROUP BY rc.partner_owner, rc.owner_phone
    ORDER BY total_revenue DESC");
$ownerStmt->execute($ownerParams);
$ownerRecap = $ownerStmt->fetchAll(PDO::FETCH_ASSOC);

$ownerDetailMap = [];
$ownerKey = static function (?string $name): string {
    $name = trim((string)$name);
    return $name !== '' ? strtolower($name) : '__tanpa_pemilik__';
};

foreach ($ownerRecap as &$or) {
    $or['rental_trips'] = (int)$or['total_trips'];
    $or['airport_trips'] = 0;
    $or['harbor_trips'] = 0;
    $or['airport_total'] = 0.0;
    $or['harbor_total'] = 0.0;
    $or['detail_rows'] = [];
}
unset($or);

$ownerIndexMap = [];
foreach ($ownerRecap as $idx => $or) {
    $ownerIndexMap[$ownerKey($or['partner_owner'] ?? '')] = $idx;
}

$ownerDetailWhere = "cb.business_id=? AND cb.status='returned' AND DATE(cb.actual_return) BETWEEN ? AND ?";
$ownerDetailParams = [$businessId, $monthStart, $monthEnd];
if ($filterOwner) {
    $ownerDetailWhere .= " AND rc.partner_owner LIKE ?";
    $ownerDetailParams[] = "%{$filterOwner}%";
}

$ownerDetailStmt = $pdo->prepare("SELECT
    rc.partner_owner,
    rc.owner_phone,
    cb.actual_return as trx_date,
    cb.guest_name,
    cb.room_number,
    cb.trip_destination,
    cb.total_price,
    cb.owner_amount,
    cb.hotel_commission,
    rc.car_name,
    rc.plate_number,
    'car_rental' as service_type
    FROM rental_car_bookings cb
    JOIN rental_cars rc ON cb.car_id = rc.id
    WHERE {$ownerDetailWhere}
    ORDER BY cb.actual_return DESC, cb.id DESC");
$ownerDetailStmt->execute($ownerDetailParams);
$ownerRentalDetails = $ownerDetailStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($ownerRentalDetails as $detail) {
    $key = $ownerKey($detail['partner_owner'] ?? '');
    $ownerDetailMap[$key][] = [
        'trx_date' => $detail['trx_date'],
        'guest_name' => $detail['guest_name'],
        'room_number' => $detail['room_number'],
        'label' => trim(($detail['car_name'] ?? '') . ' (' . ($detail['plate_number'] ?? '') . ')'),
        'service_type' => 'car_rental',
        'trip_destination' => $detail['trip_destination'],
        'total_price' => (float)$detail['total_price'],
        'owner_amount' => (float)$detail['owner_amount'],
        'hotel_commission' => (float)$detail['hotel_commission'],
    ];
}

$dropOwnerName = 'Moyong';
$dropOwnerPhone = '—';
$dropKey = $ownerKey($dropOwnerName);
$dropFilterMatch = !$filterOwner || stripos($dropOwnerName, $filterOwner) !== false;
if ($dropFilterMatch) {
    $dropStmt = $pdo->prepare("SELECT
        hi.guest_name,
        hi.room_number,
        hi.created_at as trx_date,
        hii.service_type,
        hii.description,
        hii.total_price
        FROM hotel_invoice_items hii
        JOIN hotel_invoices hi ON hii.invoice_id = hi.id
        WHERE hi.business_id=?
          AND hii.service_type IN ('airport_drop','harbor_drop')
          AND hi.status NOT IN ('cancelled')
          AND DATE(hi.created_at) BETWEEN ? AND ?
        ORDER BY hi.created_at DESC, hii.id DESC");
    $dropStmt->execute([$businessId, $monthStart, $monthEnd]);
    $dropDetails = $dropStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!isset($ownerIndexMap[$dropKey]) && !empty($dropDetails)) {
        $ownerRecap[] = [
            'partner_owner' => $dropOwnerName,
            'owner_phone' => $dropOwnerPhone,
            'total_trips' => 0,
            'total_revenue' => 0.0,
            'owner_total' => 0.0,
            'hotel_total' => 0.0,
            'avg_comm_pct' => 100,
            'cars' => 'Airport Drop, Harbor Drop',
            'rental_trips' => 0,
            'airport_trips' => 0,
            'harbor_trips' => 0,
            'airport_total' => 0.0,
            'harbor_total' => 0.0,
            'detail_rows' => [],
        ];
        $ownerIndexMap[$dropKey] = count($ownerRecap) - 1;
    }

    foreach ($dropDetails as $detail) {
        $idx = $ownerIndexMap[$dropKey] ?? null;
        if ($idx === null) continue;
        $amount = (float)$detail['total_price'];
        $ownerRecap[$idx]['total_trips'] += 1;
        $ownerRecap[$idx]['total_revenue'] += $amount;
        $ownerRecap[$idx]['owner_total'] += $amount;
        $ownerRecap[$idx]['avg_comm_pct'] = 100;
        if ($detail['service_type'] === 'airport_drop') {
            $ownerRecap[$idx]['airport_trips'] += 1;
            $ownerRecap[$idx]['airport_total'] += $amount;
        }
        if ($detail['service_type'] === 'harbor_drop') {
            $ownerRecap[$idx]['harbor_trips'] += 1;
            $ownerRecap[$idx]['harbor_total'] += $amount;
        }
        $ownerDetailMap[$dropKey][] = [
            'trx_date' => $detail['trx_date'],
            'guest_name' => $detail['guest_name'],
            'room_number' => $detail['room_number'],
            'label' => $detail['description'] ?: $detail['service_type'],
            'service_type' => $detail['service_type'],
            'trip_destination' => null,
            'total_price' => $amount,
            'owner_amount' => $amount,
            'hotel_commission' => 0.0,
        ];
    }
}

foreach ($ownerRecap as &$or) {
    $key = $ownerKey($or['partner_owner'] ?? '');
    $or['detail_rows'] = $ownerDetailMap[$key] ?? [];
}
unset($or);

// ── Recent returned rentals ────────────────────────────────────────────────────
$recentStmt = $pdo->prepare("SELECT cb.*, rc.plate_number, rc.car_name, rc.partner_owner
    FROM rental_car_bookings cb
    JOIN rental_cars rc ON cb.car_id = rc.id
    WHERE cb.business_id=? AND cb.status='returned'
    ORDER BY cb.actual_return DESC LIMIT 10");
$recentStmt->execute([$businessId]);
$recentReturns = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Build year/month options ───────────────────────────────────────────────────
$yearOptions  = range(date('Y'), date('Y') - 3);
$monthNames   = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$totalCars      = (int)$fleetData['total'];
$availableCount = (int)$fleetData['available'];
$rentedCount    = (int)$fleetData['rented'];
$maintenanceCount = (int)$fleetData['maintenance'];
$occupancyRate  = $totalCars > 0 ? round(($rentedCount / $totalCars) * 100, 1) : 0;

include '../../includes/header.php';
?>
<style>
    .rmd-page {
        padding: 1rem 1.15rem 1.25rem;
        max-width: 1400px;
        margin: 0 auto;
    }

    .rmd-header {
        margin-bottom: 1rem;
    }

    .rmd-header h1 {
        margin: 0 0 0.2rem;
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--text-primary);
    }

    .rmd-header .subtitle {
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

    .dashboard-content {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
        gap: 0.9rem;
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

    /* Active rental cards */
    .active-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
        gap: 0.75rem;
        margin-bottom: 0.5rem;
    }

    .rc-card {
        background: white;
        border-radius: 10px;
        padding: 0.95rem 1rem;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.07);
        border-left: 4px solid #10b981;
    }

    .rc-card.overdue {
        border-left-color: #ef4444;
        background: #fef2f2;
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

    .rc-info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.25rem;
        font-size: 0.8rem;
    }

    .rc-info-label {
        color: var(--text-secondary);
        font-weight: 500;
    }

    .rc-info-value {
        font-weight: 600;
        color: var(--text-primary);
    }

    /* Owner Recap */
    .filter-bar {
        background: white;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
    }

    .filter-bar select,
    .filter-bar input {
        padding: 0.4rem 0.6rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.8rem;
        background: white;
    }

    .filter-bar .filt-btn {
        padding: 0.4rem 0.9rem;
        background: #6366f1;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
    }

    .owner-recap-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .owner-recap-table th {
        background: #f8fafc;
        padding: 0.75rem 1rem;
        text-align: left;
        font-weight: 700;
        font-size: 0.78rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #e2e8f0;
    }

    .owner-recap-table td {
        padding: 0.8rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .owner-recap-table tr:last-child td {
        border-bottom: none;
    }

    .owner-recap-table tr:hover {
        background: #fafbff;
    }

    .owner-total-row {
        background: #f0fdf4 !important;
        font-weight: 700;
        border-top: 2px solid #bbf7d0 !important;
    }

    .owner-total-row td {
        color: #065f46 !important;
    }

    .available-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.65rem;
    }

    .car-available {
        background: linear-gradient(135deg, #dcfce7, #d1fae5);
        border-radius: 10px;
        padding: 0.8rem 0.75rem;
        border: 1px solid #10b981;
        text-align: center;
    }

    .car-available .icon {
        font-size: 1.8rem;
        margin-bottom: 0.4rem;
    }

    .car-available .name {
        font-weight: 800;
        color: #047857;
        margin-bottom: 0.2rem;
        font-size: 0.82rem;
    }

    .car-available .plate {
        font-size: 0.82rem;
        font-family: 'Courier New';
        font-weight: 700;
        color: #065f46;
    }

    .car-available .owner {
        font-size: 0.7rem;
        color: #059669;
        margin-top: 0.25rem;
    }

    .car-available .rate {
        font-size: 0.72rem;
        color: #047857;
        margin-top: 0.25rem;
    }

    .badge {
        display: inline-block;
        padding: 0.3rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        color: white;
    }

    .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: var(--text-secondary);
    }

    .print-btn {
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        padding: 0.35rem 0.8rem;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        color: #374151;
    }

    .print-btn:hover {
        background: #e5e7eb;
    }

    @media(max-width:768px) {
        .dashboard-content {
            grid-template-columns: 1fr
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr)
        }
    }

    @media print {

        .rmd-header a,
        .filter-bar,
        .dashboard-content .dashboard-panel:not(.print-target) {
            display: none;
        }

        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }
</style>

<div class="rmd-page">
    <!-- Header -->
    <div class="rmd-header" style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:0.5rem">
        <div>
            <h1>🚗 Dashboard Rental Mobil / Taxi</h1>
            <div class="subtitle">Monitoring armada & rekap per pemilik/mitra kendaraan</div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <a href="rental-mobil.php?view=manage" style="text-decoration:none;background:#6366f1;color:white;padding:0.4rem 0.9rem;border-radius:8px;font-size:0.8rem;font-weight:700">
                🔑 Kelola Rental
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card" style="--stat-color:#6366f1">
            <div class="label">Total Kendaraan</div>
            <div class="value"><?php echo $totalCars; ?></div>
            <div class="detail">dalam sistem</div>
        </div>
        <div class="stat-card" style="--stat-color:#10b981">
            <div class="label">Siap Disewa</div>
            <div class="value"><?php echo $availableCount; ?></div>
            <div class="detail">tersedia sekarang</div>
            <?php if ($totalCars > 0): ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo ($availableCount / $totalCars) * 100; ?>%"></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="stat-card" style="--stat-color:#f59e0b">
            <div class="label">Sedang Disewa</div>
            <div class="value"><?php echo $rentedCount; ?></div>
            <div class="detail"><?php echo $occupancyRate; ?>% okupansi</div>
            <?php if ($totalCars > 0): ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo ($rentedCount / $totalCars) * 100; ?>%"></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="stat-card" style="--stat-color:#8b5cf6">
            <div class="label">Revenue <?php echo $monthNames[$filterMonth]; ?></div>
            <div class="value">Rp <?php echo number_format($revData['total_revenue'], 0, ',', '.'); ?></div>
            <div class="detail"><?php echo $revData['total_trips']; ?> trip selesai</div>
        </div>
        <div class="stat-card" style="--stat-color:#06b6d4">
            <div class="label">Komisi Hotel</div>
            <div class="value">Rp <?php echo number_format($revData['total_hotel'], 0, ',', '.'); ?></div>
            <div class="detail"><?php echo $monthNames[$filterMonth] . ' ' . $filterYear; ?></div>
        </div>
        <div class="stat-card" style="--stat-color:#10b981">
            <div class="label">Total ke Pemilik</div>
            <div class="value">Rp <?php echo number_format($revData['total_owner'], 0, ',', '.'); ?></div>
            <div class="detail"><?php echo $monthNames[$filterMonth] . ' ' . $filterYear; ?></div>
        </div>
    </div>

    <!-- Active Rentals + Available Cars -->
    <div class="dashboard-content">
        <div class="dashboard-panel">
            <div class="panel-head">
                <h2>Rental Aktif Sekarang</h2>
                <div class="hint"><?php echo count($activeRentals); ?> unit aktif</div>
            </div>
            <?php if (!empty($activeRentals)): ?>
                <div class="active-grid" style="margin-bottom:0">
                    <?php foreach ($activeRentals as $r):
                        $now = new DateTime();
                        $endDt = new DateTime($r['end_datetime']);
                        $diff = $now->diff($endDt);
                        $isOverdue = $r['status'] === 'overdue';
                        $typeIcons = ['sedan' => '🚘', 'mpv' => '🚙', 'minibus' => '🚌', 'pickup' => '🛻', 'suv' => '🚐', 'van' => '🚐', 'other' => '🚗'];
                        $icon = $typeIcons[$r['car_type']] ?? '🚗';
                    ?>
                        <div class="rc-card <?php echo $r['status']; ?>">
                            <div class="rc-header">
                                <div class="rc-plate"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                                <span class="rc-status" style="background:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>">
                                    <?php echo $isOverdue ? '⚠ OVERDUE' : '✓ AKTIF'; ?>
                                </span>
                            </div>
                            <div style="font-size:1.3rem;margin-bottom:0.3rem"><?php echo $icon; ?></div>
                            <div style="font-size:0.84rem;font-weight:700;color:var(--text-primary);margin-bottom:0.35rem">
                                <?php echo htmlspecialchars($r['car_name']); ?>
                                <span style="font-size:0.7rem;font-weight:400;color:var(--text-secondary)"><?php echo $r['car_type']; ?></span>
                            </div>
                            <div style="border-top:1px solid rgba(0,0,0,0.08);padding-top:0.5rem;margin-top:0.5rem">
                                <div class="rc-info-row">
                                    <span class="rc-info-label">👤 Tamu</span>
                                    <span class="rc-info-value"><?php echo htmlspecialchars(substr($r['guest_name'], 0, 20)); ?></span>
                                </div>
                                <?php if ($r['room_number']): ?>
                                    <div class="rc-info-row">
                                        <span class="rc-info-label">🚪 Kamar</span>
                                        <span class="rc-info-value">#<?php echo htmlspecialchars($r['room_number']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['trip_destination']): ?>
                                    <div class="rc-info-row">
                                        <span class="rc-info-label">📍 Tujuan</span>
                                        <span class="rc-info-value" style="font-size:0.75rem"><?php echo htmlspecialchars(substr($r['trip_destination'], 0, 25)); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['partner_owner']): ?>
                                    <div class="rc-info-row">
                                        <span class="rc-info-label">🤝 Pemilik</span>
                                        <span class="rc-info-value" style="color:#6366f1"><?php echo htmlspecialchars($r['partner_owner']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div style="background:#f8fafc;border-radius:8px;padding:0.5rem 0.65rem;margin-top:0.5rem;font-size:0.75rem">
                                    <div>🚪 Mulai: <strong><?php echo date('d M H:i', strtotime($r['start_datetime'])); ?></strong></div>
                                    <div>🔑 Kembali: <strong><?php echo date('d M H:i', strtotime($r['end_datetime'])); ?></strong></div>
                                    <div style="margin-top:0.3rem;font-weight:700;color:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>">
                                        <?php if ($isOverdue): ?>
                                            ⏰ Terlambat: <?php echo $diff->days; ?>h <?php echo $diff->h; ?>j
                                        <?php else: ?>
                                            ⏳ Sisa: <?php echo $diff->days; ?>h <?php echo $diff->h; ?>j <?php echo $diff->i; ?>m
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="margin-top:0.5rem;text-align:center">
                                    <a href="rental-mobil.php?view=manage" style="color:#6366f1;font-size:0.75rem;font-weight:600;text-decoration:none">→ Kelola</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div style="font-size:2.5rem">😊</div>
                    <p>Tidak ada rental aktif saat ini</p>
                </div>
            <?php endif; ?>
        </div>

        <div style="display:grid;gap:0.8rem">
            <div class="dashboard-panel">
                <div class="panel-head">
                    <h2>Kendaraan Siap Disewa</h2>
                    <div class="hint"><?php echo count($availableList); ?> unit ready</div>
                </div>
                <?php if (!empty($availableList)): ?>
                    <div class="available-grid">
                        <?php foreach ($availableList as $c):
                            $typeIcons = ['sedan' => '🚘', 'mpv' => '🚙', 'minibus' => '🚌', 'pickup' => '🛻', 'suv' => '🚐', 'van' => '🚐', 'other' => '🚗'];
                        ?>
                            <div class="car-available">
                                <div class="icon"><?php echo $typeIcons[$c['car_type']] ?? '🚗'; ?></div>
                                <div class="name"><?php echo htmlspecialchars($c['car_name']); ?></div>
                                <div class="plate"><?php echo htmlspecialchars($c['plate_number']); ?></div>
                                <?php if ($c['partner_owner']): ?>
                                    <div class="owner">👤 <?php echo htmlspecialchars($c['partner_owner']); ?></div>
                                <?php endif; ?>
                                <div class="rate">Rp <?php echo number_format($c['daily_rate'], 0, ',', '.'); ?>/hari</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:1rem 0">
                        <div style="font-size:2rem">🅿️</div>
                        <p>Semua unit sedang digunakan</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($recentReturns)): ?>
                <div class="dashboard-panel">
                    <div class="panel-head">
                        <h2>Transaksi Terakhir</h2>
                        <div class="hint">10 terbaru</div>
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem">
                        <thead>
                            <tr>
                                <th style="background:#f8fafc;padding:0.55rem 0.7rem;text-align:left;font-size:0.7rem;font-weight:700;color:var(--text-secondary);border-bottom:1px solid #e2e8f0">Kendaraan</th>
                                <th style="background:#f8fafc;padding:0.55rem 0.7rem;text-align:right;font-size:0.7rem;font-weight:700;color:var(--text-secondary);border-bottom:1px solid #e2e8f0">Total</th>
                                <th style="background:#f8fafc;padding:0.55rem 0.7rem;text-align:right;font-size:0.7rem;font-weight:700;color:var(--text-secondary);border-bottom:1px solid #e2e8f0">Hotel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentReturns as $ret): ?>
                                <tr>
                                    <td style="padding:0.55rem 0.7rem;border-bottom:1px solid #f1f5f9">
                                        <strong><?php echo htmlspecialchars($ret['plate_number']); ?></strong>
                                        <div style="font-size:0.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($ret['car_name']); ?></div>
                                    </td>
                                    <td style="padding:0.55rem 0.7rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600">Rp <?php echo number_format($ret['total_price'], 0, ',', '.'); ?></td>
                                    <td style="padding:0.55rem 0.7rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#6366f1">Rp <?php echo number_format($ret['hotel_commission'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Owner / Partner Recap ═══ -->
    <div class="section-title">
        <span class="icon">🤝</span> Rekap Per Pemilik / Mitra
    </div>

    <div class="filter-bar">
        <form method="GET" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;width:100%">
            <select name="year">
                <?php foreach ($yearOptions as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $filterYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $filterMonth ? 'selected' : ''; ?>><?php echo $monthNames[$m]; ?></option>
                <?php endfor; ?>
            </select>
            <input type="text" name="owner" placeholder="Filter pemilik..." value="<?php echo htmlspecialchars($filterOwner); ?>" style="flex:1;min-width:140px">
            <button type="submit" class="filt-btn">Filter</button>
            <?php if ($filterOwner): ?>
                <a href="?year=<?php echo $filterYear; ?>&month=<?php echo $filterMonth; ?>" style="font-size:0.8rem;color:#6366f1;text-decoration:none;font-weight:600">Clear</a>
            <?php endif; ?>
            <button type="button" class="print-btn" onclick="window.print()">🖨️ Print</button>
        </form>
    </div>

    <?php if (empty($ownerRecap)): ?>
        <div class="empty-state" style="background:white;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,0.07)">
            <div style="font-size:2.5rem">🤝</div>
            <p>Tidak ada data rekap untuk periode <?php echo $monthNames[$filterMonth] . ' ' . $filterYear; ?></p>
            <p style="font-size:0.8rem">Tambahkan kendaraan dengan data pemilik, lalu selesaikan rental untuk melihat rekap.</p>
        </div>
    <?php else: ?>
        <?php
        $recapTotalRevenue = 0;
        $recapTotalOwner = 0;
        $recapTotalHotel = 0;
        $recapTotalTrips = 0;
        foreach ($ownerRecap as $or) {
            $recapTotalRevenue += $or['total_revenue'];
            $recapTotalOwner  += $or['owner_total'];
            $recapTotalHotel  += $or['hotel_total'];
            $recapTotalTrips  += $or['total_trips'];
        }
        ?>
        <div style="overflow-x:auto">
            <table class="owner-recap-table">
                <thead>
                    <tr>
                        <th>Pemilik / Mitra</th>
                        <th>Kendaraan / Jasa</th>
                        <th style="text-align:center">Trip</th>
                        <th style="text-align:center">Rental</th>
                        <th style="text-align:center">Airport</th>
                        <th style="text-align:center">Harbor</th>
                        <th style="text-align:right">Total Revenue</th>
                        <th style="text-align:right">Bagian Pemilik</th>
                        <th style="text-align:right">Komisi Hotel</th>
                        <th style="text-align:center">% Pemilik</th>
                        <th style="text-align:center">Kontak</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ownerRecap as $or): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700;color:#1e293b"><?php echo htmlspecialchars($or['partner_owner'] ?: '—'); ?></div>
                            </td>
                            <td style="font-size:0.78rem;color:var(--text-secondary)"><?php echo htmlspecialchars($or['cars']); ?></td>
                            <td style="text-align:center;font-weight:700"><?php echo $or['total_trips']; ?></td>
                            <td style="text-align:center"><?php echo (int)($or['rental_trips'] ?? $or['total_trips']); ?></td>
                            <td style="text-align:center"><?php echo (int)($or['airport_trips'] ?? 0); ?></td>
                            <td style="text-align:center"><?php echo (int)($or['harbor_trips'] ?? 0); ?></td>
                            <td style="text-align:right;font-weight:700">Rp <?php echo number_format($or['total_revenue'], 0, ',', '.'); ?></td>
                            <td style="text-align:right;font-weight:700;color:#059669">Rp <?php echo number_format($or['owner_total'], 0, ',', '.'); ?></td>
                            <td style="text-align:right;font-weight:700;color:#6366f1">Rp <?php echo number_format($or['hotel_total'], 0, ',', '.'); ?></td>
                            <td style="text-align:center">
                                <span style="background:#dcfce7;color:#065f46;padding:0.15rem 0.5rem;border-radius:20px;font-size:0.75rem;font-weight:700"><?php echo number_format($or['avg_comm_pct'], 0); ?>%</span>
                            </td>
                            <td style="text-align:center;font-size:0.78rem;color:var(--text-secondary)"><?php echo htmlspecialchars($or['owner_phone'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="owner-total-row">
                        <td colspan="2"><strong>TOTAL <?php echo strtoupper($monthNames[$filterMonth]); ?> <?php echo $filterYear; ?></strong></td>
                        <td style="text-align:center"><strong><?php echo $recapTotalTrips; ?></strong></td>
                        <td style="text-align:center"></td>
                        <td style="text-align:center"></td>
                        <td style="text-align:center"></td>
                        <td style="text-align:right"><strong>Rp <?php echo number_format($recapTotalRevenue, 0, ',', '.'); ?></strong></td>
                        <td style="text-align:right"><strong>Rp <?php echo number_format($recapTotalOwner, 0, ',', '.'); ?></strong></td>
                        <td style="text-align:right"><strong>Rp <?php echo number_format($recapTotalHotel, 0, ',', '.'); ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Per-owner detail cards (print-friendly) -->
        <div style="margin-top:1.5rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem">
            <?php foreach ($ownerRecap as $or): ?>
                <div style="background:white;border-radius:12px;padding:1.1rem 1.2rem;box-shadow:0 2px 8px rgba(0,0,0,0.08);border-top:4px solid #6366f1">
                    <div style="font-size:0.95rem;font-weight:800;color:#1e293b;margin-bottom:0.75rem">
                        🤝 <?php echo htmlspecialchars($or['partner_owner'] ?: 'Tanpa Pemilik'); ?>
                        <?php if ($or['owner_phone']): ?><span style="font-size:0.75rem;font-weight:400;color:var(--text-secondary)"> · <?php echo htmlspecialchars($or['owner_phone']); ?></span><?php endif; ?>
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-secondary);margin-bottom:0.75rem">🚗 <?php echo htmlspecialchars($or['cars']); ?></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;font-size:0.82rem">
                        <div style="background:#f8fafc;border-radius:8px;padding:0.6rem;text-align:center">
                            <div style="font-size:1.1rem;font-weight:800;color:#1e293b"><?php echo $or['total_trips']; ?></div>
                            <div style="color:var(--text-secondary);font-size:0.7rem">Trip</div>
                        </div>
                        <div style="background:#f0fdf4;border-radius:8px;padding:0.6rem;text-align:center">
                            <div style="font-size:1rem;font-weight:800;color:#059669">Rp <?php echo number_format($or['total_revenue'], 0, ',', '.'); ?></div>
                            <div style="color:var(--text-secondary);font-size:0.7rem">Total Revenue</div>
                        </div>
                        <div style="background:#eff6ff;border-radius:8px;padding:0.6rem;text-align:center">
                            <div style="font-size:1rem;font-weight:800;color:#2563eb">Rp <?php echo number_format($or['owner_total'], 0, ',', '.'); ?></div>
                            <div style="color:var(--text-secondary);font-size:0.7rem">Bagian Pemilik (<?php echo number_format($or['avg_comm_pct'], 0); ?>%)</div>
                        </div>
                        <div style="background:#f5f3ff;border-radius:8px;padding:0.6rem;text-align:center">
                            <div style="font-size:1rem;font-weight:800;color:#7c3aed">Rp <?php echo number_format($or['hotel_total'], 0, ',', '.'); ?></div>
                            <div style="color:var(--text-secondary);font-size:0.7rem">Komisi Hotel</div>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0.45rem;margin-top:0.6rem;font-size:0.78rem">
                        <div style="background:#fff7ed;border-radius:8px;padding:0.55rem;text-align:center">
                            <div style="font-weight:800;color:#c2410c"><?php echo (int)($or['rental_trips'] ?? $or['total_trips']); ?></div>
                            <div style="color:var(--text-secondary);font-size:0.68rem">Rental Mobil</div>
                        </div>
                        <div style="background:#ecfeff;border-radius:8px;padding:0.55rem;text-align:center">
                            <div style="font-weight:800;color:#0f766e"><?php echo (int)($or['airport_trips'] ?? 0); ?></div>
                            <div style="color:var(--text-secondary);font-size:0.68rem">Airport Drop</div>
                        </div>
                        <div style="background:#eff6ff;border-radius:8px;padding:0.55rem;text-align:center">
                            <div style="font-weight:800;color:#1d4ed8"><?php echo (int)($or['harbor_trips'] ?? 0); ?></div>
                            <div style="color:var(--text-secondary);font-size:0.68rem">Harbor Drop</div>
                        </div>
                    </div>
                    <?php if (!empty($or['detail_rows'])): ?>
                        <div style="margin-top:0.8rem;border-top:1px dashed #cbd5e1;padding-top:0.7rem">
                            <div style="font-size:0.76rem;font-weight:700;color:#475569;margin-bottom:0.45rem">Detail Transaksi</div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:0.74rem">
                                    <thead>
                                        <tr>
                                            <th style="text-align:left;padding:0.35rem 0.3rem;color:#64748b">Tanggal</th>
                                            <th style="text-align:left;padding:0.35rem 0.3rem;color:#64748b">Jenis</th>
                                            <th style="text-align:left;padding:0.35rem 0.3rem;color:#64748b">Tamu</th>
                                            <th style="text-align:right;padding:0.35rem 0.3rem;color:#64748b">Total</th>
                                            <th style="text-align:right;padding:0.35rem 0.3rem;color:#64748b">Pemilik</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($or['detail_rows'] as $detail): ?>
                                            <tr>
                                                <td style="padding:0.3rem;border-top:1px solid #eef2f7"><?php echo date('d M Y', strtotime($detail['trx_date'])); ?></td>
                                                <td style="padding:0.3rem;border-top:1px solid #eef2f7">
                                                    <?php
                                                    $detailLabel = [
                                                        'car_rental' => 'Rental Mobil',
                                                        'airport_drop' => 'Airport Drop',
                                                        'harbor_drop' => 'Harbor Drop',
                                                    ][$detail['service_type']] ?? $detail['service_type'];
                                                    echo htmlspecialchars($detailLabel);
                                                    ?>
                                                    <div style="font-size:0.68rem;color:#64748b"><?php echo htmlspecialchars($detail['label']); ?></div>
                                                </td>
                                                <td style="padding:0.3rem;border-top:1px solid #eef2f7">
                                                    <?php echo htmlspecialchars($detail['guest_name'] ?: '—'); ?>
                                                    <?php if (!empty($detail['room_number'])): ?><div style="font-size:0.68rem;color:#64748b">Kamar <?php echo htmlspecialchars($detail['room_number']); ?></div><?php endif; ?>
                                                </td>
                                                <td style="padding:0.3rem;border-top:1px solid #eef2f7;text-align:right;font-weight:700">Rp <?php echo number_format($detail['total_price'], 0, ',', '.'); ?></td>
                                                <td style="padding:0.3rem;border-top:1px solid #eef2f7;text-align:right;color:#059669;font-weight:700">Rp <?php echo number_format($detail['owner_amount'], 0, ',', '.'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>