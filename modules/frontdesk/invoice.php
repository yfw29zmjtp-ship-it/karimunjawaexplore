<?php

/**
 * INVOICE / BILL for Booking
 * Print-friendly invoice page
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Get current logged-in user
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId === 0) {
    die('Invalid Booking ID');
}

// Get primary booking details
$booking = $db->fetchOne("
    SELECT 
        b.id, b.booking_code, b.check_in_date, b.check_out_date,
        b.room_price, b.total_price, b.final_price, b.discount,
        b.status, b.payment_status, b.booking_source, b.total_nights,
        b.paid_amount, b.special_request, b.adults, b.children,
        b.created_at, b.group_id,
        g.guest_name, g.phone, g.email, g.id_card_number,
        r.room_number,
        rt.type_name as room_type
    FROM bookings b
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE b.id = ?
", [$bookingId]);

if (!$booking) {
    die('Booking not found');
}

// Find related bookings - prefer group_id, fallback to fuzzy matching for old bookings
$relatedBookings = [];
if (!empty($booking['group_id'])) {
    // Use group_id for reliable matching
    $relatedBookings = $db->fetchAll("
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            b.created_at, b.group_id,
            g.guest_name, g.phone, g.email, g.id_card_number,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.group_id = ?
        AND b.status != 'cancelled'
        ORDER BY r.room_number ASC
    ", [$booking['group_id']]);
} else {
    // Fallback: fuzzy matching for old bookings without group_id
    $relatedBookings = $db->fetchAll("
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            b.created_at,
            g.guest_name, g.phone, g.email, g.id_card_number,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE g.guest_name = ?
        AND b.check_in_date = ?
        AND b.check_out_date = ?
        AND b.booking_source = ?
        AND b.status != 'cancelled'
        AND ABS(TIMESTAMPDIFF(MINUTE, b.created_at, ?)) <= 5
        ORDER BY r.room_number ASC
    ", [
        $booking['guest_name'],
        $booking['check_in_date'],
        $booking['check_out_date'],
        $booking['booking_source'],
        $booking['created_at']
    ]);
}

// If no related found or only 1, use single booking
$allBookings = (!empty($relatedBookings) && count($relatedBookings) > 1) ? $relatedBookings : [$booking];
$isMultiRoom = count($allBookings) > 1;

// Collect all booking IDs for payment query
$allBookingIds = array_column($allBookings, 'id');
$placeholders = implode(',', array_fill(0, count($allBookingIds), '?'));

// Get payment history for all related bookings
$payments = $db->fetchAll("
    SELECT 
        bp.booking_id, bp.amount, bp.payment_method, bp.payment_date, bp.notes,
        bk.booking_code, r.room_number
    FROM booking_payments bp
    LEFT JOIN bookings bk ON bp.booking_id = bk.id
    LEFT JOIN rooms r ON bk.room_id = r.id
    WHERE bp.booking_id IN ($placeholders)
    ORDER BY bp.payment_date ASC
", $allBookingIds);

// Get extras for all related bookings
$allExtras = $db->fetchAll("
    SELECT be.*, r.room_number
    FROM booking_extras be
    LEFT JOIN bookings bk ON be.booking_id = bk.id
    LEFT JOIN rooms r ON bk.room_id = r.id
    WHERE be.booking_id IN ($placeholders)
    ORDER BY r.room_number ASC, be.created_at ASC
", $allBookingIds);

$combinedExtrasTotal = 0;
foreach ($allExtras as $ex) {
    $combinedExtrasTotal += $ex['total_price'];
}

// Calculate combined totals
$totalPaid = 0;
foreach ($payments as $payment) {
    $totalPaid += $payment['amount'];
}

// Fallback: sum paid_amount from all bookings if no payment records
if ($totalPaid == 0) {
    foreach ($allBookings as $bk) {
        $totalPaid += $bk['paid_amount'];
    }
}

$combinedFinalPrice = 0;
$combinedTotalPrice = 0;
$combinedDiscount = 0;
foreach ($allBookings as $bk) {
    $combinedFinalPrice += $bk['final_price'];
    $combinedTotalPrice += $bk['total_price'];
    $combinedDiscount += $bk['discount'];
}

$remaining = $combinedFinalPrice - $totalPaid;
$isPdf = isset($_GET['pdf']);

// Determine payment status
$overallStatus = 'unpaid';
$overallLabel = 'UNPAID';
if ($totalPaid >= $combinedFinalPrice && $combinedFinalPrice > 0) {
    $overallStatus = 'paid';
    $overallLabel = 'PAID';
} elseif ($totalPaid > 0) {
    $overallStatus = 'partial';
    $overallLabel = 'DOWN PAYMENT';
}

// Get business info
$businessId = $_SESSION['business_id'] ?? 1;
$business = $db->fetchOne("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// Get invoice logo from PDF settings (Settings > Pengaturan Laporan PDF)
$invoiceLogoRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_logo_' . ACTIVE_BUSINESS_ID]
);
$logoUrl = $invoiceLogoRow['setting_value'] ?? null;
// Fallback to company logo if no invoice logo
if (empty($logoUrl)) {
    $logoUrl = getBusinessLogo();
}

// Get company settings from master DB
$masterDb = Database::getInstance();
$settingsQuery = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'";
$settingsResult = $masterDb->fetchAll($settingsQuery);
$companySettings = [];
foreach ($settingsResult as $setting) {
    if (strpos($setting['setting_key'], 'company_logo_') === 0) continue; // skip logo keys
    $key = str_replace('company_', '', $setting['setting_key']);
    $companySettings[$key] = $setting['setting_value'];
}

// Fallback for company name
if (empty($companySettings['name'])) {
    $companySettings['name'] = $business['business_name'] ?? 'Narayana Hotel';
}

// Get Bank Transfer Info from settings
$bankAccNumberRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_bank_account_number_' . ACTIVE_BUSINESS_ID]
);
$bankAccountNumber = $bankAccNumberRow['setting_value'] ?? '1926663992';

$bankAccNameRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_bank_account_name_' . ACTIVE_BUSINESS_ID]
);
$bankAccountName = $bankAccNameRow['setting_value'] ?? 'BNI Jepara';

// Get Swift Code from settings
$swiftRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_swift_code_' . ACTIVE_BUSINESS_ID]
);
$swiftCode = $swiftRow['setting_value'] ?? '';

// Get accounting info for signature
$accountingNameRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_accounting_name_' . ACTIVE_BUSINESS_ID]
);
$accountingName = $accountingNameRow['setting_value'] ?? '';

// If no saved name, use current logged-in user's name
if (empty($accountingName)) {
    $accountingName = $currentUser['full_name'] ?? 'Accounting Staff';
}

$accountingTitleRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_accounting_title_' . ACTIVE_BUSINESS_ID]
);
$accountingTitle = $accountingTitleRow['setting_value'] ?? 'Accounting Staff';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $isMultiRoom ? $booking['guest_name'] . ' (' . count($allBookings) . ' rooms)' : $booking['booking_code']; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:wght@300;400;500;600&family=Source+Code+Pro:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #e8e6e1;
            color: #2d2d2d;
            line-height: 1.6;
            padding: 12px;
            font-size: 13px;
            font-weight: 400;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .invoice-page {
            max-width: 794px;
            min-height: 1100px;
            margin: 0 auto;
            background: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 8px 30px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
        }

        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-family: 'DM Serif Display', serif;
            font-size: 80px;
            font-weight: 400;
            opacity: 0.06;
            pointer-events: none;
            z-index: 1;
            white-space: nowrap;
            letter-spacing: 12px;
        }

        .wm-paid {
            color: #2e7d5a;
        }

        .wm-unpaid {
            color: #c0392b;
        }

        .wm-partial {
            color: #b8860b;
        }

        /* Top accent line - Elegant Blue Gradient */
        .top-border {
            height: 4px;
            background: linear-gradient(90deg, #0D3B66 0%, #1a4d7d 50%, #D4AF37 100%);
        }

        /* Header */
        .header {
            padding: 20px 36px 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 2;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .brand-text h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.3rem;
            font-weight: 400;
            color: #0D3B66;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .brand-text .slogan {
            font-size: 0.6rem;
            font-weight: 400;
            font-style: italic;
            color: #D4AF37;
            margin-top: 1px;
            letter-spacing: 0.5px;
        }

        .brand-text .hotel-detail {
            font-size: 0.55rem;
            color: #6b7d94;
            margin-top: 3px;
            line-height: 1.4;
        }

        .header-right {
            text-align: right;
        }

        .header-right .inv-label {
            font-family: 'DM Serif Display', serif;
            font-size: 1.4rem;
            font-weight: 400;
            color: #0D3B66;
            letter-spacing: 5px;
            line-height: 1;
        }

        .header-right .inv-meta {
            font-size: 0.68rem;
            color: #8a8a8a;
            margin-top: 6px;
            line-height: 1.5;
        }

        .header-right .inv-meta strong {
            color: #0D3B66;
            font-family: 'Source Code Pro', monospace;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .header-right .inv-date {
            font-size: 0.62rem;
            color: #9a9590;
            margin-top: 2px;
        }

        /* Separator */
        .sep-line {
            height: 1px;
            margin: 0 36px;
            background: #e8e6e1;
        }

        /* Status bar */
        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 36px;
            position: relative;
            z-index: 2;
        }

        .status-bar .hotel-contact {
            font-size: 0.62rem;
            color: #9a9590;
            line-height: 1.5;
            letter-spacing: 0.2px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 14px;
            font-weight: 600;
            font-size: 0.58rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            border-radius: 2px;
        }

        .badge-paid {
            color: #2e7d5a;
            background: #f0f7f4;
            border: 1px solid #c8e0d4;
        }

        .badge-unpaid {
            color: #c0392b;
            background: #fdf2f0;
            border: 1px solid #f0cdc8;
        }

        .badge-partial {
            color: #b8860b;
            background: #fdf8ef;
            border: 1px solid #eddcb5;
        }

        /* Body */
        .body {
            padding: 2px 36px 16px;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        /* Info cards */
        .info-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 6px 0 12px;
        }

        .info-card {
            padding: 7px 10px;
            background: #fafaf8;
            border-radius: 4px;
        }

        .info-card .card-title {
            font-family: 'DM Serif Display', serif;
            font-size: 0.58rem;
            font-weight: 400;
            color: #0D3B66;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 4px;
            padding-bottom: 3px;
            border-bottom: 1px solid #D4AF37;
        }

        .info-card .row {
            display: flex;
            justify-content: space-between;
            padding: 1px 0;
            font-size: 0.66rem;
        }

        .info-card .row .lbl {
            color: #9a9590;
            font-weight: 400;
        }

        .info-card .row .val {
            color: #2d2d2d;
            font-weight: 500;
            text-align: right;
            max-width: 62%;
        }

        /* Room table */
        .tbl-room {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0 8px;
            font-size: 0.78rem;
        }

        .tbl-room thead th {
            background: #0D3B66;
            color: #fff;
            padding: 9px 14px;
            font-weight: 500;
            font-size: 0.62rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            text-align: left;
        }

        .tbl-room thead th:last-child,
        .tbl-room thead th:nth-child(3),
        .tbl-room thead th:nth-child(4) {
            text-align: right;
        }

        .tbl-room tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0eeea;
            color: #3d3d3d;
        }

        .tbl-room tbody tr:last-child td {
            border-bottom: none;
        }

        .tbl-room tbody td:last-child,
        .tbl-room tbody td:nth-child(3),
        .tbl-room tbody td:nth-child(4) {
            text-align: right;
        }

        .tbl-room tbody td:last-child {
            font-weight: 600;
            font-family: 'Source Code Pro', monospace;
            font-size: 0.78rem;
            color: #0D3B66;
        }

        .tbl-room tbody td:nth-child(4) {
            font-family: 'Source Code Pro', monospace;
            color: #5a5a5a;
        }

        .tbl-room tbody {
            border-bottom: 2px solid #0D3B66;
        }

        /* Summary */
        .summary-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: 8px;
        }

        .summary {
            width: 290px;
        }

        .sum-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 0.78rem;
            border-bottom: 1px solid #f0eeea;
        }

        .sum-row .sl {
            color: #8a8a8a;
            font-weight: 400;
        }

        .sum-row .sv {
            font-weight: 600;
            font-family: 'Source Code Pro', monospace;
            color: #2d2d2d;
        }

        .sum-row.disc .sv {
            color: #c0392b;
        }

        .sum-total {
            display: flex;
            justify-content: space-between;
            padding: 10px 0 6px;
            margin-top: 6px;
            border-top: 2px solid #D4AF37;
            font-size: 0.95rem;
        }

        .sum-total .sl {
            font-family: 'DM Serif Display', serif;
            font-weight: 400;
            color: #0D3B66;
            font-size: 0.95rem;
        }

        .sum-total .sv {
            font-weight: 700;
            font-family: 'Source Code Pro', monospace;
            color: #0D3B66;
        }

        .sum-paid {
            display: flex;
            justify-content: space-between;
            padding: 6px 10px;
            margin-top: 8px;
            background: #f0f7f4;
            border-radius: 3px;
            font-size: 0.78rem;
        }

        .sum-paid .sl {
            color: #2e7d5a;
            font-weight: 500;
        }

        .sum-paid .sv {
            color: #2e7d5a;
            font-weight: 700;
            font-family: 'Source Code Pro', monospace;
        }

        .sum-due {
            display: flex;
            justify-content: space-between;
            padding: 6px 10px;
            margin-top: 4px;
            background: #fdf2f0;
            border-radius: 3px;
            font-size: 0.82rem;
        }

        .sum-due .sl {
            color: #c0392b;
            font-weight: 600;
        }

        .sum-due .sv {
            color: #c0392b;
            font-weight: 700;
            font-family: 'Source Code Pro', monospace;
        }

        /* Payment section */
        .pay-section {
            margin-top: 14px;
        }

        .sec-title {
            font-family: 'DM Serif Display', serif;
            font-size: 0.72rem;
            font-weight: 400;
            color: #0D3B66;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #D4AF37;
        }

        .tbl-pay {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.74rem;
        }

        .tbl-pay th {
            background: #fafaf8;
            padding: 7px 12px;
            text-align: left;
            font-weight: 500;
            font-size: 0.6rem;
            color: #9a9590;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #e8e6e1;
        }

        .tbl-pay td {
            padding: 7px 12px;
            border-bottom: 1px solid #f0eeea;
            color: #3d3d3d;
        }

        /* Note box */
        .note-box {
            margin-top: 10px;
            padding: 8px 12px;
            background: #f0f5fb;
            border-left: 3px solid #0D3B66;
            border-radius: 0 3px 3px 0;
            font-size: 0.76rem;
            color: #5a5a5a;
        }

        .note-box strong {
            color: #0D3B66;
            font-weight: 600;
        }

        /* Footer */
        .footer {
            margin-top: auto;
            text-align: center;
            padding: 12px 36px 8px;
            border-top: 1px solid #e8e6e1;
        }

        .footer .ty {
            font-family: 'DM Serif Display', serif;
            font-size: 0.82rem;
            font-weight: 400;
            color: #0D3B66;
            margin-bottom: 3px;
        }

        .footer .fc {
            font-size: 0.58rem;
            color: #9a9590;
            line-height: 1.5;
            letter-spacing: 0.3px;
        }

        .bottom-border {
            height: 4px;
            background: linear-gradient(90deg, #0D3B66 0%, #1a4d7d 50%, #D4AF37 100%);
        }

        /* Action buttons */
        .actions {
            text-align: center;
            padding: 18px 0;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .btn {
            padding: 10px 26px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            letter-spacing: 0.3px;
        }

        .btn-dark {
            background: #0D3B66;
            color: #fff;
        }

        .btn-dark:hover {
            background: #1a4d7d;
        }

        .btn-light {
            background: #f0f5fb;
            color: #0D3B66;
            border: 1px solid #0D3B66;
        }

        .btn-light:hover {
            background: #e8f1f8;
        }

        /* Signature Section */
        .signature-section {
            margin-top: 20px;
            padding: 15px 14px;
            border-top: 2px solid #0D3B66;
            border-bottom: 2px solid #D4AF37;
            background: rgba(13, 59, 102, 0.03);
        }

        /* Admin Control */
        .admin-control {
            margin-bottom: 20px;
            padding: 12px;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
        }

        .control-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
            margin-bottom: 10px;
        }

        .qris-upload-form {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .qris-upload-form input[type="file"] {
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            font-size: 0.8rem;
            flex: 1;
        }

        .qris-upload-form button {
            padding: 6px 14px;
            background: #0D3B66;
            color: white;
            border: none;
            border-radius: 3px;
            font-size: 0.8rem;
            cursor: pointer;
            font-weight: 500;
        }

        .qris-upload-form button:hover {
            background: #1a4d7d;
        }

        .qris-current {
            margin-top: 10px;
            font-size: 0.75rem;
            color: #666;
        }

        .qris-current img {
            max-width: 80px;
            margin-top: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .invoice-page {
                box-shadow: none;
                min-height: 277mm;
            }

            .actions {
                display: none !important;
            }

            .admin-control {
                display: none !important;
            }
        }

        @page {
            margin: 5mm;
            size: A4;
        }
    </style>
</head>

<body>
    <div class="invoice-page" id="invoiceContent">
        <div class="watermark wm-<?php echo $overallStatus; ?>"><?php echo $overallLabel; ?></div>
        <div class="top-border"></div>

        <!-- Header -->
        <div class="header">
            <div class="brand">
                <?php if ($logoUrl): ?>
                    <img class="brand-logo" src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($companySettings['name']); ?>">
                <?php else: ?>
                    <div style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,#1a2332,#2c3e50);display:flex;align-items:center;justify-content:center;color:#d4cfc7;font-family:'DM Serif Display',serif;font-size:2.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.1);">N</div>
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo htmlspecialchars($companySettings['name']); ?></h1>
                    <div class="slogan">"The Paradise of Java"</div>
                    <div class="hotel-detail">Jl. Kasimo Jatikerep, Karimunjawa, Jepara 59455<br>Tel: 081222228590 &middot; narayanahotelkarimunjawa@gmail.com</div>
                </div>
            </div>
            <div class="header-right">
                <div class="inv-label">INVOICE</div>
                <div class="inv-meta">
                    <strong><?php echo htmlspecialchars($allBookings[0]['booking_code']); ?></strong>
                    <?php if ($isMultiRoom && count($allBookings) > 1): ?>
                        <span style="color:#8b7355;">+ <?php echo count($allBookings) - 1; ?> room<?php echo count($allBookings) - 1 > 1 ? 's' : ''; ?></span>
                    <?php endif; ?>
                </div>
                <div class="inv-date"><?php echo date('d F Y', strtotime($booking['created_at'])); ?></div>
                <span class="status-badge badge-<?php echo $overallStatus; ?>" style="margin-top:6px;"><?php echo $overallLabel; ?></span>
            </div>
        </div>

        <div class="sep-line"></div>

        <!-- Body -->
        <div class="body">
            <div class="info-cards">
                <div class="info-card">
                    <div class="card-title">Bill To</div>
                    <div class="row"><span class="lbl">Name</span><span class="val"><?php echo htmlspecialchars($booking['guest_name']); ?></span></div>
                    <div class="row"><span class="lbl">Phone</span><span class="val"><?php echo htmlspecialchars($booking['phone'] ?? '-'); ?></span></div>
                    <div class="row"><span class="lbl">Email</span><span class="val"><?php echo htmlspecialchars($booking['email'] ?? '-'); ?></span></div>
                    <?php if (!empty($booking['id_card_number'])): ?>
                        <div class="row"><span class="lbl">ID No.</span><span class="val"><?php echo htmlspecialchars($booking['id_card_number']); ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="info-card">
                    <div class="card-title">Reservation</div>
                    <div class="row"><span class="lbl">Check-in</span><span class="val"><?php echo date('D, d M Y', strtotime($booking['check_in_date'])); ?></span></div>
                    <div class="row"><span class="lbl">Check-out</span><span class="val"><?php echo date('D, d M Y', strtotime($booking['check_out_date'])); ?></span></div>
                    <div class="row"><span class="lbl">Duration</span><span class="val"><?php echo $booking['total_nights']; ?> Night<?php echo $booking['total_nights'] > 1 ? 's' : ''; ?></span></div>
                    <div class="row"><span class="lbl">Guests</span><span class="val"><?php echo ($booking['adults'] ?? 1); ?> Adult<?php echo ($booking['adults'] ?? 1) > 1 ? 's' : ''; ?><?php echo ($booking['children'] ?? 0) > 0 ? ', ' . $booking['children'] . ' Child' . ($booking['children'] > 1 ? 'ren' : '') : ''; ?></span></div>
                    <div class="row"><span class="lbl">Source</span><span class="val"><?php echo ucwords(str_replace('_', ' ', $booking['booking_source'] ?? 'Walk-in')); ?></span></div>
                </div>
            </div>

            <!-- Room & Price Table -->
            <table class="tbl-room">
                <thead>
                    <tr>
                        <th style="width:15%">Room</th>
                        <th>Description</th>
                        <th style="width:10%">Nights</th>
                        <th style="width:22%">Rate / Night</th>
                        <th style="width:22%">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allBookings as $bk): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($bk['room_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bk['room_type']); ?></td>
                            <td><?php echo $bk['total_nights']; ?></td>
                            <td>Rp <?php echo number_format($bk['room_price'], 0, ',', '.'); ?></td>
                            <td>Rp <?php echo number_format($bk['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($allExtras as $ex): ?>
                        <tr>
                            <td><?php echo $isMultiRoom ? htmlspecialchars($ex['room_number']) : ''; ?></td>
                            <td><?php echo htmlspecialchars($ex['item_name']); ?></td>
                            <td><?php echo $ex['quantity']; ?></td>
                            <td>Rp <?php echo number_format($ex['unit_price'], 0, ',', '.'); ?></td>
                            <td>Rp <?php echo number_format($ex['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Summary -->
            <div class="summary-wrap">
                <div class="summary">
                    <div class="sum-row">
                        <span class="sl">Subtotal</span>
                        <span class="sv">Rp <?php echo number_format($combinedTotalPrice, 0, ',', '.'); ?></span>
                    </div>
                    <?php if ($combinedDiscount > 0): ?>
                        <div class="sum-row disc">
                            <span class="sl">Discount</span>
                            <span class="sv">- Rp <?php echo number_format($combinedDiscount, 0, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($combinedExtrasTotal > 0): ?>
                        <?php
                        // Build label from actual extra item names
                        $extraNames = array_unique(array_column($allExtras, 'item_name'));
                        $extrasLabel = implode(', ', $extraNames);
                        ?>
                        <div class="sum-row">
                            <span class="sl"><?php echo htmlspecialchars($extrasLabel); ?></span>
                            <span class="sv">+ Rp <?php echo number_format($combinedExtrasTotal, 0, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="sum-total">
                        <span class="sl">TOTAL</span>
                        <span class="sv">Rp <?php echo number_format($combinedFinalPrice, 0, ',', '.'); ?></span>
                    </div>
                    <div class="sum-paid">
                        <span class="sl">Amount Paid</span>
                        <span class="sv">Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></span>
                    </div>
                    <?php if ($remaining > 0): ?>
                        <div class="sum-due">
                            <span class="sl">Balance Due</span>
                            <span class="sv">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment History -->
            <?php if (!empty($payments)): ?>
                <div class="pay-section">
                    <div class="sec-title">Payment History</div>
                    <table class="tbl-pay">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <?php if ($isMultiRoom): ?><th>Room</th><?php endif; ?>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?php echo date('d M Y, H:i', strtotime($p['payment_date'])); ?></td>
                                    <?php if ($isMultiRoom): ?><td><?php echo htmlspecialchars($p['room_number'] ?? '-'); ?></td><?php endif; ?>
                                    <td><?php echo strtoupper($p['payment_method']); ?></td>
                                    <td style="font-weight:600;">Rp <?php echo number_format($p['amount'], 0, ',', '.'); ?></td>
                                    <td style="color:#999;"><?php echo htmlspecialchars($p['notes'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Special Request -->
            <?php if (!empty($booking['special_request'])): ?>
                <div class="note-box">
                    <strong>Guest Note:</strong> <?php echo nl2br(htmlspecialchars($booking['special_request'])); ?>
                </div>
            <?php endif; ?>

            <!-- Payment Methods (Vertical Stack) -->
            <div style="margin: 0.6rem 0;">
                <!-- Bank Transfer -->
                <div style="display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.4rem 0;">
                    <div style="flex-shrink: 0; margin-top: 2px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0D3B66" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
                            <line x1="1" y1="10" x2="23" y2="10" />
                        </svg>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-size: 0.62rem; color: #6b7d94; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.15rem; font-weight: 500;">Transfer</div>
                        <div style="font-size: 0.8rem; font-weight: 600; color: #0D3B66; word-break: break-all;"><?php echo htmlspecialchars($bankAccountNumber); ?></div>
                        <div style="font-size: 0.62rem; color: #6b7d94; margin-top: 0.1rem;">a.n <?php echo htmlspecialchars($bankAccountName); ?></div>
                        <?php if (!empty($swiftCode)): ?>
                            <div style="font-size: 0.62rem; color: #0D3B66; margin-top: 0.15rem; font-weight: 500;">Swift: <?php echo htmlspecialchars($swiftCode); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <div style="display: flex; flex-direction: column; align-items: center; margin-top: 1.2rem; max-width: 300px; margin-left: auto; margin-right: auto;">
                <div style="border-top: 1px solid #0D3B66; width: 100%; padding-top: 2.5rem; text-align: center; min-height: 30px;">
                    <div style="font-size: 0.75rem; color: #0D3B66; font-weight: 600;">
                        <?php echo !empty($accountingName) ? htmlspecialchars($accountingName) : '..........................'; ?>
                    </div>
                    <div style="font-size: 0.65rem; color: #6b7d94; margin-top: 0.15rem;">
                        <?php echo htmlspecialchars($accountingTitle); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="ty">Thank you for staying with us</div>
            <div class="fc">
                <?php echo htmlspecialchars($companySettings['name']); ?><br>
                Jl. Kasimo Jatikerep Karimunjawa Jepara Jawatengah 59455<br>
                081222228590 &middot; narayanahotelkarimunjawa@gmail.com &middot; www.narayanakarimunjawa.com
            </div>
        </div>
    </div>

    <div class="bottom-border"></div>
    </div>

    <!-- Actions -->
    <div class="actions">
        <button class="btn btn-dark" onclick="savePDF()">Save as PDF</button>
        <button class="btn btn-light" onclick="window.print()">Print</button>
    </div>

    <script>
        function savePDF() {
            document.title = 'Invoice_<?php echo $isMultiRoom ? preg_replace('/[^a-zA-Z0-9]/', '_', $booking['guest_name']) : $booking['booking_code']; ?>_<?php echo date('Ymd'); ?>';
            window.print();
        }

        <?php if ($isPdf): ?>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        <?php endif; ?>
    </script>
</body>

</html>