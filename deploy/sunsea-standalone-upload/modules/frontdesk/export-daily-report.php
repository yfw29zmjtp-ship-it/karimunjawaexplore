<?php

/**
 * EXPORT DAILY REPORT TO PDF
 * Generates PDF with logo, header, and professional formatting
 */

define('APP_ACCESS', true);
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    exit('Access denied');
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$printerName = $currentUser['username'] ?? 'System';
$printerRole = $currentUser['role'] ?? 'Staff';
$today = date('Y-m-d');
$todayDisplay = date('l, d F Y');
$printTime = date('d F Y H:i:s');

// Get company info with logo
$company = getCompanyInfo();

// Collect data
try {
    // Occupancy
    $totalRooms = $db->fetchOne("SELECT COUNT(*) as total FROM rooms WHERE status != 'maintenance'")['total'];
    $occupiedRooms = $db->fetchOne("SELECT COUNT(DISTINCT room_id) as occupied FROM bookings WHERE status = 'checked_in'")['occupied'];
    $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

    // In-house
    $inHouseGuests = $db->fetchAll("SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date, b.payment_status
        FROM bookings b INNER JOIN guests g ON b.guest_id = g.id INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in' ORDER BY r.room_number ASC");

    // Check-in today - only those with check-in date today but NOT yet checked in (status = confirmed)
    $checkInToday = $db->fetchAll("SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date
        FROM bookings b INNER JOIN guests g ON b.guest_id = g.id INNER JOIN rooms r ON b.room_id = r.id
        WHERE DATE(b.check_in_date) = ? AND b.status = 'confirmed'
        ORDER BY b.check_in_date ASC", [$today]);

    // Check-out today - only checked_in guests with checkout date today
    $checkOutToday = $db->fetchAll("SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date
        FROM bookings b INNER JOIN guests g ON b.guest_id = g.id INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_out_date = ? AND b.status = 'checked_in' ORDER BY r.room_number ASC", [$today]);

    // Tomorrow
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    // Check-out tomorrow
    $checkOutTomorrow = $db->fetchAll("SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date, g.phone
        FROM bookings b INNER JOIN guests g ON b.guest_id = g.id INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_out_date = ? AND b.status = 'checked_in' ORDER BY r.room_number ASC", [$tomorrow]);

    // Arrival tomorrow
    $arrivalTomorrow = $db->fetchAll("SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date, g.phone, b.guest_count
        FROM bookings b INNER JOIN guests g ON b.guest_id = g.id INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_in_date = ? AND b.status IN ('confirmed', 'pending') ORDER BY r.room_number ASC", [$tomorrow]);

    // Breakfast orders — deduplicate: only newest record per guest per date per room
    $breakfastOrders = $db->fetchAll("SELECT bo.* FROM breakfast_orders bo
        WHERE bo.breakfast_date = ?
        AND bo.id = (
            SELECT MAX(bo2.id) FROM breakfast_orders bo2
            WHERE bo2.guest_name = bo.guest_name
              AND bo2.breakfast_date = bo.breakfast_date
              AND bo2.room_number = bo.room_number
        )
        ORDER BY bo.breakfast_time ASC, bo.id ASC", [$today]);

    foreach ($breakfastOrders as &$order) {
        $order['menu_items'] = json_decode($order['menu_items'], true) ?: [];
        $decodedRoom = json_decode($order['room_number'], true);
        if (is_array($decodedRoom)) {
            $order['room_number'] = implode(', ', $decodedRoom);
        }
    }
    unset($order);

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
    error_log("Export PDF Error: " . $e->getMessage());
    exit('Error loading data');
}

// Set headers for PDF download
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Daily Report - <?php echo $todayDisplay; ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 15mm 10mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.35;
            color: #1e293b;
            max-width: 190mm;
            margin: 0 auto;
        }

        /* Header */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 10px;
            border-bottom: 2px solid #4f46e5;
            margin-bottom: 10px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-logo {
            width: 96px;
            height: 96px;
            border-radius: 14px;
            object-fit: contain;
        }

        .report-logo-icon {
            width: 96px;
            height: 96px;
            border-radius: 14px;
            background: #eef2ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }

        .hotel-name {
            font-size: 15pt;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1px;
        }

        .hotel-detail {
            font-size: 7.5pt;
            color: #64748b;
            line-height: 1.25;
            max-width: 320px;
        }

        .report-title {
            font-size: 11pt;
            font-weight: 700;
            color: #4f46e5;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            text-align: right;
        }

        .report-date {
            font-size: 9pt;
            color: #64748b;
            text-align: right;
            margin-top: 2px;
        }

        .report-printer {
            font-size: 7.5pt;
            color: #94a3b8;
            text-align: right;
            margin-top: 3px;
            font-style: italic;
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-bottom: 14px;
        }

        .stat-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 7px 5px;
            text-align: center;
        }

        .stat-val {
            font-size: 16pt;
            font-weight: 800;
            color: #4f46e5;
            line-height: 1;
        }

        .stat-lbl {
            font-size: 7pt;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 2px;
        }

        /* Section */
        .rpt-section {
            margin-bottom: 12px;
            page-break-inside: avoid;
        }

        .rpt-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px solid #cbd5e1;
            margin-bottom: 4px;
        }

        .sec-title {
            font-size: 10pt;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .sec-count {
            font-size: 8pt;
            font-weight: 600;
            color: #4f46e5;
            background: #eef2ff;
            padding: 1px 7px;
            border-radius: 8px;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f1f5f9;
            padding: 4px 6px;
            text-align: left;
            font-weight: 600;
            font-size: 8pt;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        td {
            padding: 4px 6px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 9.5pt;
            color: #334155;
        }

        tr:hover {
            background: #f8fafc;
        }

        .room-tag {
            display: inline-block;
            background: #4f46e5;
            color: #fff !important;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 9pt;
        }

        .pay-paid {
            color: #16a34a;
            font-weight: 600;
            font-size: 8.5pt;
            text-transform: uppercase;
        }

        .pay-unpaid {
            color: #dc2626;
            font-weight: 600;
            font-size: 8.5pt;
            text-transform: uppercase;
        }

        .pay-partial {
            color: #d97706;
            font-weight: 600;
            font-size: 8.5pt;
            text-transform: uppercase;
        }

        .loc-tag {
            font-size: 8.5pt;
            font-weight: 500;
        }

        .menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 9pt;
        }

        .menu-list li {
            padding: 1px 0;
        }

        .menu-list .qty {
            font-weight: 600;
            color: #4f46e5;
            margin-right: 3px;
        }

        /* Print Footer — normal flow, not fixed */
        .print-footer {
            margin-top: 20px;
            padding-top: 8px;
            font-size: 8pt;
            color: #999;
            text-align: center;
            border-top: 1px dashed #e5e7eb;
            page-break-inside: avoid;
        }

        .print-footer .sys {
            font-weight: 600;
            color: #4f46e5;
            letter-spacing: 0.3px;
            font-size: 8.5pt;
        }

        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                max-width: 100%;
                width: 100%;
            }

            .room-tag,
            .stat-item,
            th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <div class="report-header">
        <div class="header-left">
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
        <div>
            <div class="report-title">Daily Report</div>
            <div class="report-date"><?php echo $todayDisplay; ?></div>
            <div class="report-printer">Printed by: <?php echo htmlspecialchars($currentUser['full_name'] ?? $printerName); ?></div>
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
                <span class="sec-title">👥 In-House Guests</span>
                <span class="sec-count"><?php echo count($inHouseGuests); ?></span>
            </div>
            <table>
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
                            <td><span class="pay-<?php echo $g['payment_status']; ?>"><?php echo strtoupper($g['payment_status']); ?></span></td>
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
                <span class="sec-title">📥 Check-in Today</span>
                <span class="sec-count"><?php echo count($checkInToday); ?></span>
            </div>
            <table>
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
                <span class="sec-title">📤 Check-out Today</span>
                <span class="sec-count"><?php echo count($checkOutToday); ?></span>
            </div>
            <table>
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
                <span class="sec-title">📤 Check-out Tomorrow</span>
                <span class="sec-count"><?php echo count($checkOutTomorrow); ?></span>
            </div>
            <table>
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
                <span class="sec-title">✈️ Arrival Tomorrow</span>
                <span class="sec-count"><?php echo count($arrivalTomorrow); ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
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
        <div class="rpt-section">
            <div class="rpt-section-head">
                <span class="sec-title">🍳 Breakfast Orders</span>
                <span class="sec-count"><?php echo count($breakfastOrders); ?></span>
            </div>

            <?php if (!empty($menuRecap)): ?>
                <table style="width:100%;border-collapse:collapse;margin-bottom:10px;border:1px solid #fde68a;border-radius:6px;overflow:hidden">
                    <thead>
                        <tr style="background:#fef3c7">
                            <th colspan="2" style="padding:6px 10px;text-align:left;font-size:8.5pt;color:#92400e;text-transform:uppercase">📋 Rekap Total per Menu (untuk kitchen)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menuRecap as $menuName => $qty): ?>
                            <tr style="border-top:1px solid #fde68a">
                                <td style="padding:5px 10px;font-size:9pt;font-weight:600"><?php echo htmlspecialchars($menuName); ?></td>
                                <td style="padding:5px 10px;font-size:9pt;font-weight:800;color:#d97706;text-align:right;width:80px">×<?php echo $qty; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Time</th>
                        <th>Guest</th>
                        <th>Pax</th>
                        <th>Location</th>
                        <th>Menu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakfastOrders as $order): ?>
                        <tr>
                            <td style="max-width:90px"><?php
                                                        $roomArr = array_map('trim', explode(',', $order['room_number'] ?: '-'));
                                                        $roomChunks = array_chunk($roomArr, 2);
                                                        foreach ($roomChunks as $rc) {
                                                            echo '<span class="room-tag" style="display:inline-block;margin:1px 0">' . htmlspecialchars(implode(', ', $rc)) . '</span><br>';
                                                        }
                                                        ?></td>
                            <td><?php echo date('H:i', strtotime($order['breakfast_time'])); ?></td>
                            <td style="max-width:180px;word-wrap:break-word"><?php
                                                                                // Split combined guest names, show max 4 per line
                                                                                $names = array_map('trim', explode(',', $order['guest_name']));
                                                                                $chunks = array_chunk($names, 4);
                                                                                echo htmlspecialchars(implode(",\n", array_map(function ($c) {
                                                                                    return implode(', ', $c);
                                                                                }, $chunks)));
                                                                                ?></td>
                            <td><?php echo $order['total_pax']; ?></td>
                            <td><span class="loc-tag"><?php echo $order['location'] === 'restaurant' ? '🍽️ Restaurant' : ($order['location'] === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></span></td>
                            <td>
                                <ul class="menu-list">
                                    <?php foreach ($order['menu_items'] as $item): ?>
                                        <li>
                                            <span class="qty">x<?php echo $item['quantity']; ?></span> <?php echo htmlspecialchars($item['menu_name']); ?>
                                            <?php if (!empty($item['is_custom'])): ?><span style="font-size:7pt;color:#f59e0b;font-weight:700"> (Manual)</span><?php endif; ?>
                                            <?php if (empty($item['is_free']) && (float)($item['price'] ?? 0) > 0): ?>
                                                <span style="font-size:7.5pt;color:#10b981;font-weight:600"> — Rp <?php echo number_format((float)$item['price'] * (int)($item['quantity'] ?? 1), 0, ',', '.'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['note'])): ?><br><span style="font-size:7.5pt;color:#92400e;font-style:italic;margin-left:18px">↳ <?php echo htmlspecialchars($item['note']); ?></span><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if ((float)($order['total_price'] ?? 0) > 0): ?>
                                    <div style="font-size:8pt;font-weight:700;color:#10b981;margin-top:3px;border-top:1px dashed #e5e7eb;padding-top:3px">Total Extra: Rp <?php echo number_format((float)$order['total_price'], 0, ',', '.'); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="print-footer">
        <div><span class="sys">✓ Printed from ADF System — Narayana Hotel © 2026</span></div>
        <div style="font-size: 6pt; color: #bbb; margin-top: 1px;">Printed by: <?php echo htmlspecialchars($currentUser['full_name'] ?? $printerName); ?> | <?php echo $printTime; ?></div>
    </div>

    <?php if (empty($_GET['noprint'])): ?>
        <script>
            window.onload = function() {
                window.print();
            };
        </script>
    <?php endif; ?>
</body>

</html>