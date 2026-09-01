<?php

/**
 * FRONT DESK - LAPORAN HARIAN
 * Laporan occupancy, in-house, check-in/out hari ini, dan breakfast orders
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Laporan Harian';
$today = date('Y-m-d');
$todayDisplay = date('l, d F Y');

// Get company info
$company = getCompanyInfo();

// ============================================
// DATA COLLECTION
// ============================================

try {
    // 1. OCCUPANCY STATS
    $totalRoomsQuery = "SELECT COUNT(*) as total FROM rooms WHERE status != 'maintenance'";
    $totalRooms = $db->fetchOne($totalRoomsQuery)['total'];

    $occupiedRoomsQuery = "SELECT COUNT(DISTINCT room_id) as occupied 
                           FROM bookings 
                           WHERE status = 'checked_in'";
    $occupiedRooms = $db->fetchOne($occupiedRoomsQuery)['occupied'];

    $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

    // 2. IN-HOUSE GUESTS
    $inHouseQuery = "SELECT 
            b.id as booking_id,
            b.booking_code,
            g.guest_name,
            r.room_number,
            b.check_in_date,
            b.check_out_date,
            b.payment_status
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC";
    $inHouseGuests = $db->fetchAll($inHouseQuery);

    // 3. CHECK-IN TODAY - Only guests with check-in date TODAY but NOT YET checked in (status = confirmed)
    $checkInTodayQuery = "SELECT 
            b.booking_code,
            g.guest_name,
            r.room_number,
            b.check_in_date,
            b.check_out_date,
            b.actual_checkin_time
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE DATE(b.check_in_date) = ? AND b.status = 'confirmed'
        ORDER BY b.check_in_date ASC";
    $checkInToday = $db->fetchAll($checkInTodayQuery, [$today]);

    // 4. CHECK-OUT TODAY - Only guests with status checked_in and checkout date is today
    $checkOutTodayQuery = "SELECT 
            b.booking_code,
            g.guest_name,
            r.room_number,
            b.check_in_date,
            b.check_out_date
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_out_date = ? AND b.status = 'checked_in'
        ORDER BY r.room_number ASC";
    $checkOutToday = $db->fetchAll($checkOutTodayQuery, [$today]);

    // 5. TOMORROW DATE
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    // 6. CHECK-OUT TOMORROW
    $checkOutTomorrowQuery = "SELECT 
            b.booking_code,
            g.guest_name,
            g.phone,
            r.room_number,
            b.check_in_date,
            b.check_out_date
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_out_date = ? AND b.status = 'checked_in'
        ORDER BY r.room_number ASC";
    $checkOutTomorrow = $db->fetchAll($checkOutTomorrowQuery, [$tomorrow]);

    // 7. ARRIVAL TOMORROW (All reservations)
    $arrivalTomorrowQuery = "SELECT 
            b.booking_code,
            g.guest_name,
            g.phone,
            r.room_number,
            b.check_in_date,
            b.check_out_date,
            b.guest_count
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_in_date = ? AND b.status IN ('confirmed', 'pending')
        ORDER BY r.room_number ASC";
    $arrivalTomorrow = $db->fetchAll($arrivalTomorrowQuery, [$tomorrow]);

    // 8. BREAKFAST ORDERS TODAY — direct DB query (same logic as breakfast.php sidebar)
    $breakfastOrders = [];
    try {
        $bfQuery = "SELECT bo.* FROM breakfast_orders bo
            WHERE bo.breakfast_date = ?
            AND bo.id = (
                SELECT MAX(bo2.id) FROM breakfast_orders bo2
                WHERE bo2.guest_name = bo.guest_name
                  AND bo2.breakfast_date = bo.breakfast_date
                  AND bo2.room_number = bo.room_number
            )
            ORDER BY bo.breakfast_time ASC, bo.id ASC";
        $breakfastOrders = $db->fetchAll($bfQuery, [$today]);
        foreach ($breakfastOrders as &$bfOrder) {
            $bfOrder['menu_items'] = json_decode($bfOrder['menu_items'], true) ?: [];
            $decodedRoom = json_decode($bfOrder['room_number'], true);
            if (is_array($decodedRoom)) {
                $bfOrder['room_number'] = implode(', ', $decodedRoom);
            }
        }
        unset($bfOrder);
    } catch (Exception $e) {
    }

    // Rekap total pesanan per menu (untuk kitchen prep)
    $menuRecap = [];
    foreach ($breakfastOrders as $order) {
        foreach ($order['menu_items'] as $item) {
            $menuName = trim($item['menu_name'] ?? '');
            if ($menuName === '') continue;
            $qty = (int)($item['quantity'] ?? 1);
            if (!isset($menuRecap[$menuName])) $menuRecap[$menuName] = 0;
            $menuRecap[$menuName] += $qty;
        }
    }
    arsort($menuRecap);
} catch (Exception $e) {
    error_log("Laporan Error: " . $e->getMessage());
    $error = $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
    /* ===== LAPORAN HARIAN - ELEGANT COMPACT ===== */
    .laporan-container {
        max-width: 100%;
        margin: 0 auto;
        padding: 1rem 0.5rem;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-bottom: 1rem;
    }

    .action-buttons .btn {
        padding: 0.45rem 1.1rem;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.8rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: all 0.2s;
        color: #fff;
        letter-spacing: 0.3px;
    }

    .action-buttons .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    }

    .btn-pdf {
        background: #4f46e5;
        color: #fff;
    }

    .btn-pdf:hover {
        background: #4338ca;
    }

    .btn-print {
        background: #475569;
        color: #fff;
    }

    .btn-print:hover {
        background: #334155;
    }

    .btn-wa {
        background: #22c55e;
        color: #fff;
    }

    .btn-wa:hover {
        background: #16a34a;
    }

    .report-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 0.6rem;
        border-bottom: 2px solid #4f46e5;
        margin-bottom: 1rem;
        gap: 0.75rem;
    }

    [data-theme="dark"] .report-header {
        border-bottom-color: #6366f1;
    }

    .report-header-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .report-logo {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        object-fit: contain;
        flex-shrink: 0;
    }

    .report-logo-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #eef2ff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    [data-theme="dark"] .report-logo-icon {
        background: #312e81;
    }

    .report-header-left .hotel-name {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }

    [data-theme="dark"] .report-header-left .hotel-name {
        color: #e2e8f0;
    }

    .report-header-left .hotel-detail {
        font-size: 0.65rem;
        color: #64748b;
        line-height: 1.4;
        margin-top: 2px;
    }

    .report-header-right {
        text-align: right;
        flex-shrink: 0;
    }

    .report-header-right .report-title {
        font-size: 0.75rem;
        font-weight: 700;
        color: #4f46e5;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin: 0;
    }

    [data-theme="dark"] .report-header-right .report-title {
        color: #818cf8;
    }

    .report-header-right .report-date {
        font-size: 0.7rem;
        color: #64748b;
        margin-top: 2px;
    }

    .report-stamp {
        text-align: center;
        margin-top: 1.25rem;
        padding-top: 0.75rem;
        border-top: 1px dashed #e2e8f0;
    }

    [data-theme="dark"] .report-stamp {
        border-top-color: #334155;
    }

    .report-stamp .stamp-line {
        font-size: 0.6rem;
        color: #94a3b8;
        line-height: 1.6;
    }

    .report-stamp .stamp-system {
        font-weight: 600;
        color: #4f46e5;
        font-size: 0.6rem;
        letter-spacing: 0.5px;
    }

    [data-theme="dark"] .report-stamp .stamp-system {
        color: #818cf8;
    }

    .stats-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.6rem;
        margin-bottom: 1.25rem;
    }

    .stat-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 0.5rem;
        text-align: center;
        transition: all 0.2s;
    }

    .stat-item:hover {
        border-color: #c7d2fe;
        background: #eef2ff;
    }

    [data-theme="dark"] .stat-item {
        background: #1e293b;
        border-color: #334155;
    }

    [data-theme="dark"] .stat-item:hover {
        border-color: #4f46e5;
        background: #1e1b4b;
    }

    .stat-item .stat-val {
        font-size: 1.5rem;
        font-weight: 800;
        color: #4f46e5;
        line-height: 1;
    }

    [data-theme="dark"] .stat-item .stat-val {
        color: #818cf8;
    }

    .stat-item .stat-lbl {
        font-size: 0.6rem;
        color: #94a3b8;
        font-weight: 500;
        margin-top: 0.2rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .rpt-section {
        margin-bottom: 0.75rem;
    }

    .rpt-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.4rem 0;
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 0.35rem;
    }

    [data-theme="dark"] .rpt-section-head {
        border-bottom-color: #475569;
    }

    .rpt-section-head .sec-title {
        font-size: 0.8rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    [data-theme="dark"] .rpt-section-head .sec-title {
        color: #e2e8f0;
    }

    .rpt-section-head .sec-count {
        font-size: 0.65rem;
        font-weight: 600;
        color: #4f46e5;
        background: #eef2ff;
        padding: 0.15rem 0.5rem;
        border-radius: 10px;
    }

    [data-theme="dark"] .rpt-section-head .sec-count {
        background: #312e81;
        color: #a5b4fc;
    }

    .rpt-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.78rem;
    }

    .rpt-table th {
        background: #f8fafc;
        padding: 0.35rem 0.5rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.68rem;
        color: #64748b;
        border-bottom: 1px solid #e2e8f0;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    [data-theme="dark"] .rpt-table th {
        background: #1e293b;
        color: #94a3b8;
        border-bottom-color: #334155;
    }

    .rpt-table td {
        padding: 0.35rem 0.5rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 0.78rem;
    }

    [data-theme="dark"] .rpt-table td {
        border-bottom-color: #1e293b;
        color: #cbd5e1;
    }

    .rpt-table tbody tr:hover {
        background: #f8fafc;
    }

    [data-theme="dark"] .rpt-table tbody tr:hover {
        background: #1e293b;
    }

    .room-tag {
        display: inline-block;
        background: #4f46e5;
        color: #fff !important;
        padding: 0.1rem 0.4rem;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.7rem;
        min-width: 28px;
        text-align: center;
    }

    [data-theme="dark"] .room-tag {
        background: #6366f1;
    }

    .pay-badge {
        display: inline-block;
        padding: 0.1rem 0.35rem;
        border-radius: 3px;
        font-size: 0.62rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .pay-paid {
        background: #dcfce7;
        color: #166534;
    }

    .pay-unpaid {
        background: #fee2e2;
        color: #991b1b;
    }

    .pay-partial {
        background: #fef3c7;
        color: #92400e;
    }

    [data-theme="dark"] .pay-paid {
        background: rgba(22, 163, 74, 0.2);
        color: #4ade80;
    }

    [data-theme="dark"] .pay-unpaid {
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
    }

    [data-theme="dark"] .pay-partial {
        background: rgba(245, 158, 11, 0.2);
        color: #fcd34d;
    }

    .loc-tag {
        display: inline-block;
        padding: 0.1rem 0.35rem;
        border-radius: 3px;
        font-size: 0.62rem;
        font-weight: 500;
    }

    .loc-restaurant {
        background: #ede9fe;
        color: #5b21b6;
    }

    .loc-room_service {
        background: #e0e7ff;
        color: #3730a3;
    }

    .loc-take_away {
        background: #fef3c7;
        color: #92400e;
    }

    [data-theme="dark"] .loc-restaurant {
        background: rgba(139, 92, 246, 0.2);
        color: #a78bfa;
    }

    [data-theme="dark"] .loc-room_service {
        background: rgba(99, 102, 241, 0.2);
        color: #818cf8;
    }

    [data-theme="dark"] .loc-take_away {
        background: rgba(245, 158, 11, 0.2);
        color: #fcd34d;
    }

    .menu-list {
        list-style: none;
        padding: 0;
        margin: 0;
        font-size: 0.72rem;
    }

    .menu-list li {
        padding: 1px 0;
    }

    .menu-list .qty {
        font-weight: 600;
        color: #4f46e5;
        margin-right: 3px;
    }

    [data-theme="dark"] .menu-list .qty {
        color: #818cf8;
    }

    .empty-msg {
        text-align: center;
        padding: 1rem;
        color: #94a3b8;
        font-size: 0.78rem;
        font-style: italic;
    }

    /* Breakfast card layout - 2 column grid */
    .bf-cards {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
    }

    .bf-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.55rem 0.7rem;
        position: relative;
        transition: all 0.2s;
    }

    .bf-card:hover {
        border-color: #c7d2fe;
        box-shadow: 0 2px 8px rgba(79, 70, 229, 0.08);
    }

    [data-theme="dark"] .bf-card {
        background: #1e293b;
        border-color: #334155;
    }

    [data-theme="dark"] .bf-card:hover {
        border-color: #4f46e5;
        box-shadow: 0 2px 8px rgba(99, 102, 241, 0.15);
    }

    .bf-card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.25rem;
        gap: 0.4rem;
    }

    .bf-card-guest {
        font-weight: 700;
        font-size: 0.78rem;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    [data-theme="dark"] .bf-card-guest {
        color: #e2e8f0;
    }

    .bf-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem 0.5rem;
        align-items: center;
        font-size: 0.68rem;
        color: #64748b;
        margin-bottom: 0.2rem;
    }

    [data-theme="dark"] .bf-card-meta {
        color: #94a3b8;
    }

    .bf-card-menus {
        display: flex;
        flex-wrap: wrap;
        gap: 0.2rem;
    }

    .bf-card-menus .bf-menu-tag {
        background: #eef2ff;
        color: #4338ca;
        padding: 0.08rem 0.35rem;
        border-radius: 4px;
        font-size: 0.65rem;
        font-weight: 500;
        white-space: nowrap;
    }

    [data-theme="dark"] .bf-card-menus .bf-menu-tag {
        background: #312e81;
        color: #a5b4fc;
    }

    .bf-card-actions {
        display: flex;
        gap: 0.3rem;
        margin-top: 0.3rem;
        justify-content: flex-end;
    }

    .bf-card-actions .bf-edit,
    .bf-card-actions .bf-del {
        border: none;
        padding: 0.15rem 0.45rem;
        border-radius: 4px;
        font-size: 0.62rem;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.15s;
    }

    .bf-card-actions .bf-edit {
        background: #e0e7ff;
        color: #3730a3;
    }

    .bf-card-actions .bf-edit:hover {
        background: #c7d2fe;
    }

    .bf-card-actions .bf-del {
        background: #fee2e2;
        color: #991b1b;
    }

    .bf-card-actions .bf-del:hover {
        background: #fecaca;
    }

    [data-theme="dark"] .bf-card-actions .bf-edit {
        background: rgba(99, 102, 241, 0.2);
        color: #a5b4fc;
    }

    [data-theme="dark"] .bf-card-actions .bf-del {
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
    }

    @media (max-width: 640px) {
        .bf-cards {
            grid-template-columns: 1fr;
        }
    }

    .print-footer {
        display: none;
    }

    @media print {
        body * {
            visibility: hidden;
        }

        .laporan-container,
        .laporan-container * {
            visibility: visible;
        }

        .laporan-container {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            max-width: 100%;
            padding: 12mm 15mm;
        }

        .action-buttons {
            display: none !important;
        }

        .bf-act {
            display: none !important;
        }

        .bf-card-actions {
            display: none !important;
        }

        .bf-cards {
            grid-template-columns: repeat(2, 1fr);
        }

        .stat-item {
            background: #f8fafc !important;
            border: 1px solid #d1d5db !important;
        }

        .rpt-table th {
            background: #f3f4f6 !important;
        }

        .room-tag {
            background: #4f46e5 !important;
            color: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .pay-badge,
        .loc-tag {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .report-logo {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .report-stamp {
            border-top: 1px dashed #d1d5db !important;
        }

        .report-stamp .stamp-system {
            color: #4f46e5 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .rpt-section {
            page-break-inside: avoid;
        }

        .print-footer {
            display: block;
            position: fixed;
            bottom: 8mm;
            right: 12mm;
            font-size: 7pt;
            color: #999;
            text-align: right;
        }

        .print-footer .sys {
            font-weight: 600;
            color: #4f46e5;
        }
    }

    @media (max-width: 640px) {
        .laporan-container {
            padding: 0.5rem;
        }

        .stats-row {
            grid-template-columns: repeat(2, 1fr);
        }

        .report-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .report-header-right {
            text-align: left;
        }

        .rpt-table {
            font-size: 0.72rem;
        }

        .action-buttons {
            flex-wrap: wrap;
        }
    }
</style>

<div class="laporan-container">
    <!-- Action Buttons -->
    <div class="action-buttons">
        <button class="btn btn-pdf" onclick="exportToPDF()">📄 Export PDF</button>
        <button class="btn btn-print" onclick="window.print()">🖨️ Print</button>
        <button class="btn btn-wa" onclick="shareToWhatsApp()">📱 WhatsApp</button>
    </div>

    <!-- Report Header -->
    <div class="report-header">
        <div class="report-header-left">
            <?php
            $logoUrl = $company['invoice_logo'] ?? $company['logo'] ?? null;
            if ($logoUrl): ?>
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo" class="report-logo">
            <?php else: ?>
                <div class="report-logo-icon"><?php echo $company['icon']; ?></div>
            <?php endif; ?>
            <div>
                <div class="hotel-name"><?php echo htmlspecialchars($company['name']); ?></div>
                <div class="hotel-detail">
                    <?php if ($company['address']): echo htmlspecialchars($company['address']);
                    endif; ?>
                    <?php if ($company['phone']): ?> | Tel: <?php echo htmlspecialchars($company['phone']); ?><?php endif; ?>
                        <?php if ($company['email']): ?> | <?php echo htmlspecialchars($company['email']); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="report-header-right">
            <div class="report-title">Laporan Harian</div>
            <div class="report-date"><?php echo $todayDisplay; ?></div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-item">
            <div class="stat-val"><?php echo $occupancyRate; ?>%</div>
            <div class="stat-lbl">Occupancy</div>
        </div>
        <div class="stat-item">
            <div class="stat-val"><?php echo count($inHouseGuests); ?></div>
            <div class="stat-lbl">In House</div>
        </div>
        <div class="stat-item">
            <div class="stat-val"><?php echo count($checkInToday); ?></div>
            <div class="stat-lbl">Check-in</div>
        </div>
        <div class="stat-item">
            <div class="stat-val"><?php echo count($checkOutToday); ?></div>
            <div class="stat-lbl">Check-out</div>
        </div>
    </div>

    <!-- In-House Guests -->
    <?php if (count($inHouseGuests) > 0): ?>
        <div class="rpt-section">
            <div class="rpt-section-head">
                <h3 class="sec-title">👥 In-House Guests</h3>
                <span class="sec-count"><?php echo count($inHouseGuests); ?></span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th>Code</th>
                        <th>In</th>
                        <th>Out</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inHouseGuests as $g): ?>
                        <tr>
                            <td><span class="room-tag"><?php echo htmlspecialchars($g['room_number']); ?></span></td>
                            <td><?php echo htmlspecialchars($g['guest_name']); ?></td>
                            <td><?php echo htmlspecialchars($g['booking_code']); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_in_date'])); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_out_date'])); ?></td>
                            <td><span class="pay-badge pay-<?php echo $g['payment_status']; ?>"><?php echo strtoupper($g['payment_status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Check-in Today -->
    <?php if (count($checkInToday) > 0): ?>
        <div class="rpt-section">
            <div class="rpt-section-head">
                <h3 class="sec-title">📥 Check-in Today</h3>
                <span class="sec-count"><?php echo count($checkInToday); ?></span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th>Code</th>
                        <th>Out</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checkInToday as $g): ?>
                        <tr>
                            <td><span class="room-tag"><?php echo htmlspecialchars($g['room_number']); ?></span></td>
                            <td><?php echo htmlspecialchars($g['guest_name']); ?></td>
                            <td><?php echo htmlspecialchars($g['booking_code']); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_out_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Check-out Today -->
    <?php if (count($checkOutToday) > 0): ?>
        <div class="rpt-section">
            <div class="rpt-section-head">
                <h3 class="sec-title">📤 Check-out Today</h3>
                <span class="sec-count"><?php echo count($checkOutToday); ?></span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th>Code</th>
                        <th>In</th>
                        <th>Out</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checkOutToday as $g): ?>
                        <tr>
                            <td><span class="room-tag"><?php echo htmlspecialchars($g['room_number']); ?></span></td>
                            <td><?php echo htmlspecialchars($g['guest_name']); ?></td>
                            <td><?php echo htmlspecialchars($g['booking_code']); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_in_date'])); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_out_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Check-out Tomorrow -->
    <?php if (count($checkOutTomorrow) > 0): ?>
        <div class="rpt-section">
            <div class="rpt-section-head">
                <h3 class="sec-title">📤 Check-out Tomorrow</h3>
                <span class="sec-count"><?php echo count($checkOutTomorrow); ?></span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th>Code</th>
                        <th>In</th>
                        <th>Out</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checkOutTomorrow as $g): ?>
                        <tr>
                            <td><span class="room-tag"><?php echo htmlspecialchars($g['room_number']); ?></span></td>
                            <td><?php echo htmlspecialchars($g['guest_name']); ?></td>
                            <td><?php echo htmlspecialchars($g['booking_code']); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_in_date'])); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_out_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Arrival Tomorrow -->
    <?php if (count($arrivalTomorrow) > 0): ?>
        <div class="rpt-section">
            <div class="rpt-section-head">
                <h3 class="sec-title">✈️ Arrival Tomorrow</h3>
                <span class="sec-count"><?php echo count($arrivalTomorrow); ?></span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th>Phone</th>
                        <th>Code</th>
                        <th>Pax</th>
                        <th>In</th>
                        <th>Out</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($arrivalTomorrow as $g): ?>
                        <tr>
                            <td><span class="room-tag"><?php echo htmlspecialchars($g['room_number']); ?></span></td>
                            <td><?php echo htmlspecialchars($g['guest_name']); ?></td>
                            <td><?php echo htmlspecialchars($g['phone'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($g['booking_code']); ?></td>
                            <td><?php echo $g['guest_count'] ?: '1'; ?></td>
                            <td><?php echo date('d M', strtotime($g['check_in_date'])); ?></td>
                            <td><?php echo date('d M', strtotime($g['check_out_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Breakfast Orders -->
    <?php if (count($breakfastOrders) > 0): ?>
        <div class="rpt-section" style="margin-top: 1rem; border-top: 2px solid #1e293b; padding-top: 0.75rem;">
            <div class="rpt-section-head">
                <h3 class="sec-title">🍳 Breakfast Orders</h3>
                <span class="sec-count"><?php echo count($breakfastOrders); ?></span>
            </div>

            <?php if (!empty($menuRecap)): ?>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.6rem .85rem;margin-bottom:.85rem;">
                    <div style="font-size:.75rem;font-weight:700;color:#92400e;margin-bottom:.4rem;">📋 Rekap Total per Menu (untuk kitchen)</div>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                        <?php foreach ($menuRecap as $menuName => $qty): ?>
                            <span style="background:#fff;border:1px solid #fde68a;border-radius:6px;padding:.25rem .6rem;font-size:.72rem;font-weight:600;color:#374151;">
                                <?php echo htmlspecialchars($menuName); ?>
                                <strong style="color:#d97706;margin-left:.25rem;">×<?php echo $qty; ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bf-cards">
                <?php foreach ($breakfastOrders as $order): ?>
                    <div class="bf-card" id="bf-row-<?php echo $order['id']; ?>">
                        <div class="bf-card-top">
                            <span class="bf-card-guest"><?php echo htmlspecialchars($order['guest_name']); ?></span>
                            <span class="room-tag"><?php echo htmlspecialchars($order['room_number'] ?: '-'); ?></span>
                        </div>
                        <div class="bf-card-meta">
                            <span>🕐 <?php echo $order['breakfast_time'] ? date('H:i', strtotime($order['breakfast_time'])) : '-'; ?></span>
                            <span>👤 <?php echo $order['total_pax']; ?> pax</span>
                            <span class="loc-tag loc-<?php echo $order['location']; ?>"><?php echo $order['location'] === 'restaurant' ? '🍽️ Restaurant' : ($order['location'] === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></span>
                        </div>
                        <div class="bf-card-menus">
                            <?php foreach ($order['menu_items'] as $item): ?>
                                <span class="bf-menu-tag">
                                    x<?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['menu_name']); ?>
                                    <?php if (!empty($item['is_custom'])): ?><span style="font-size:.55rem;color:#f59e0b;font-weight:700"> (Manual)</span><?php endif; ?>
                                    <?php if (empty($item['is_free']) && (float)($item['price'] ?? 0) > 0): ?>
                                        <span style="font-size:.6rem;color:#10b981;font-weight:600;margin-left:2px">Rp <?php echo number_format((float)$item['price'] * (int)($item['quantity'] ?? 1), 0, ',', '.'); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ((float)($order['total_price'] ?? 0) > 0): ?>
                            <div style="font-size:.68rem;font-weight:700;color:#10b981;margin-top:.3rem">💰 Total Extra: Rp <?php echo number_format((float)$order['total_price'], 0, ',', '.'); ?></div>
                        <?php endif; ?>
                        <div class="bf-card-actions">
                            <button class="bf-edit" onclick="editBreakfastOrder(<?php echo $order['id']; ?>)" title="Edit">✏️ Edit</button>
                            <button class="bf-del" onclick="deleteBreakfastOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars(addslashes($order['guest_name'])); ?>')" title="Delete">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Report Stamp -->
    <div class="report-stamp">
        <div class="stamp-line">Dicetak oleh: <strong><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff'); ?></strong></div>
        <div class="stamp-system">Dicetak dari ADF System — Narayana Hotel © 2026</div>
        <div class="stamp-line"><?php echo date('d M Y, H:i'); ?> WIB</div>
    </div>
</div>

<script>
    function exportToPDF() {
        var w = window.open('export-daily-report.php', '_blank');
        if (!w || w.closed) {
            window.location.href = 'export-daily-report.php';
        }
    }

    function deleteBreakfastOrder(id, guestName) {
        if (!confirm('Hapus order breakfast untuk ' + guestName + '?')) return;
        fetch('../../api/breakfast-order-action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'delete',
                    id: id
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    var row = document.getElementById('bf-row-' + id);
                    if (row) row.remove();
                    // Update count
                    var countEl = document.querySelector('.rpt-section .sec-count');
                    var cards = document.querySelectorAll('.bf-card');
                    if (countEl) countEl.textContent = cards.length;
                    showNotification('Order breakfast dihapus', 'success');
                } else {
                    showNotification(data.message || 'Gagal menghapus', 'error');
                }
            })
            .catch(() => showNotification('Gagal menghapus', 'error'));
    }

    function editBreakfastOrder(id) {
        window.location.href = 'breakfast.php?edit=' + id;
    }

    function shareToWhatsApp() {
        // Build WhatsApp message with link to report
        var reportUrl = window.location.origin + window.location.pathname.replace('laporan.php', 'export-daily-report.php') + '?noprint=1';

        var text = '*📊 DAILY REPORT - <?php echo date("d M Y"); ?>*\n\n';
        text += '🏨 *<?php echo addslashes($company['name'] ?? 'Hotel'); ?>*\n\n';
        text += '📈 *Occupancy:* <?php echo $occupancyRate; ?>%\n';
        text += '👥 *In House:* <?php echo count($inHouseGuests); ?> tamu\n';
        text += '📥 *Check-in Hari Ini:* <?php echo count($checkInToday); ?>\n';
        text += '📤 *Check-out Hari Ini:* <?php echo count($checkOutToday); ?>\n';
        <?php if (count($breakfastOrders) > 0): ?>
            text += '🍳 *Breakfast Orders:* <?php echo count($breakfastOrders); ?>\n';
        <?php endif; ?>
        text += '\n📄 *Lihat Laporan Lengkap:*\n' + reportUrl;

        var whatsappUrl = 'https://wa.me/?text=' + encodeURIComponent(text);
        window.open(whatsappUrl, '_blank');
    }
</script>

<!-- Print Footer Watermark -->
<div class="print-footer">
    <div><span class="sys">✓ Dicetak dari ADF System — Narayana Hotel</span></div>
    <div style="font-size: 6pt; color: #ccc; margin-top: 2px;">Oleh: <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff'); ?> | <?php echo date('d M Y H:i'); ?></div>
</div>

<?php include '../../includes/footer.php'; ?>