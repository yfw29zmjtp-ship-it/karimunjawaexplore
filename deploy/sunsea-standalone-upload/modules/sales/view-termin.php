<?php
/**
 * CQC Professional Invoice - Compact A4 Version
 * Professional invoice display and print - fits on single A4 page
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$print = isset($_GET['print']) && $_GET['print'] == '1';

if (!$id) {
    header('Location: index-cqc.php');
    exit;
}

// Get invoice
$stmt = $pdo->prepare("
    SELECT ti.*, p.project_code, p.project_name, p.client_name, p.client_phone, 
           p.client_email, p.location, p.solar_capacity_kwp
    FROM cqc_termin_invoices ti
    LEFT JOIN cqc_projects p ON ti.project_id = p.id
    WHERE ti.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found.");
}

// Get CQC business settings from master database
$db = Database::getInstance();
$businessId = 7; // CQC business ID

// Default company info
$companyName = 'CQC Enjiniring';
$companyTagline = 'Solar Panel Installation Contractor';
$companyAddress = 'Address not configured';
$companyCity = '';
$companyPhone = '-';
$companyEmail = '-';
$companyNPWP = '-';
$companyLogo = '';
$bankName = '';
$bankAccount = '';
$bankHolder = '';

// Try to load from business_settings
try {
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM business_settings WHERE business_id = ?", [$businessId]);
    foreach ($settings as $s) {
        switch ($s['setting_key']) {
            case 'business_name': if ($s['setting_value']) $companyName = $s['setting_value']; break;
            case 'tagline': if ($s['setting_value']) $companyTagline = $s['setting_value']; break;
            case 'address': if ($s['setting_value']) $companyAddress = $s['setting_value']; break;
            case 'city': if ($s['setting_value']) $companyCity = $s['setting_value']; break;
            case 'phone': if ($s['setting_value']) $companyPhone = $s['setting_value']; break;
            case 'email': if ($s['setting_value']) $companyEmail = $s['setting_value']; break;
            case 'npwp': if ($s['setting_value']) $companyNPWP = $s['setting_value']; break;
            case 'logo': if ($s['setting_value']) $companyLogo = $s['setting_value']; break;
            case 'bank_name': if ($s['setting_value']) $bankName = $s['setting_value']; break;
            case 'bank_account': if ($s['setting_value']) $bankAccount = $s['setting_value']; break;
            case 'bank_holder': if ($s['setting_value']) $bankHolder = $s['setting_value']; break;
        }
    }
} catch (Exception $e) {
    // Settings table might not exist
}

// Try load from CQC config file as fallback
$configPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(dirname(__DIR__));
$configFile = $configPath . '/config/businesses/cqc.php';
if (file_exists($configFile)) {
    $cqcConfig = include $configFile;
    if ($companyName === 'CQC Enjiniring' && isset($cqcConfig['name'])) {
        $companyName = $cqcConfig['name'];
    }
    if (empty($companyLogo) && isset($cqcConfig['logo'])) {
        $companyLogo = $cqcConfig['logo'];
    }
    if ($companyAddress === 'Address not configured' && isset($cqcConfig['address'])) {
        $companyAddress = $cqcConfig['address'];
    }
    if (empty($companyCity) && isset($cqcConfig['city'])) {
        $companyCity = $cqcConfig['city'];
    }
    if ($companyPhone === '-' && isset($cqcConfig['phone'])) {
        $companyPhone = $cqcConfig['phone'];
    }
    if ($companyEmail === '-' && isset($cqcConfig['email'])) {
        $companyEmail = $cqcConfig['email'];
    }
    if ($companyNPWP === '-' && isset($cqcConfig['npwp'])) {
        $companyNPWP = $cqcConfig['npwp'];
    }
    if (empty($bankName) && isset($cqcConfig['bank_name'])) {
        $bankName = $cqcConfig['bank_name'];
    }
    if (empty($bankAccount) && isset($cqcConfig['bank_account'])) {
        $bankAccount = $cqcConfig['bank_account'];
    }
    if (empty($bankHolder) && isset($cqcConfig['bank_holder'])) {
        $bankHolder = $cqcConfig['bank_holder'];
    }
}

// Full address
$fullAddress = $companyAddress;
if ($companyCity) {
    $fullAddress .= ', ' . $companyCity;
}

$pageTitle = "Invoice " . $invoice['invoice_number'];

// Number to words in English for Indonesian Rupiah
function numberToWords($number) {
    $number = abs(intval($number));
    $words = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
              'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    
    if ($number < 20) {
        return $words[$number];
    }
    if ($number < 100) {
        return $tens[intval($number / 10)] . ($number % 10 ? ' ' . $words[$number % 10] : '');
    }
    if ($number < 1000) {
        return $words[intval($number / 100)] . ' Hundred' . ($number % 100 ? ' ' . numberToWords($number % 100) : '');
    }
    if ($number < 1000000) {
        return numberToWords(intval($number / 1000)) . ' Thousand' . ($number % 1000 ? ' ' . numberToWords($number % 1000) : '');
    }
    if ($number < 1000000000) {
        return numberToWords(intval($number / 1000000)) . ' Million' . ($number % 1000000 ? ' ' . numberToWords($number % 1000000) : '');
    }
    if ($number < 1000000000000) {
        return numberToWords(intval($number / 1000000000)) . ' Billion' . ($number % 1000000000 ? ' ' . numberToWords(intval($number % 1000000000)) : '');
    }
    return numberToWords(intval($number / 1000000000000)) . ' Trillion' . ($number % 1000000000000 ? ' ' . numberToWords(intval($number % 1000000000000)) : '');
}

$totalInWords = numberToWords($invoice['total_amount']) . ' Rupiah';

// Format date for invoice
function formatInvDate($date) {
    return date('F j, Y', strtotime($date));
}

// Logo path check - check uploads and assets folders
$logoPath = '';
$logoExists = false;
$possibleLogos = [
    ['folder' => 'uploads', 'file' => $companyLogo],
    ['folder' => 'uploads', 'file' => 'logos/' . $companyLogo],
    ['folder' => 'uploads', 'file' => 'logos/cqc_logo.png'],
    ['folder' => 'assets/images', 'file' => $companyLogo],
    ['folder' => 'assets/images', 'file' => 'cqc-logo.png'],
    ['folder' => 'assets/img', 'file' => 'cqc-logo.png'],
];
foreach ($possibleLogos as $logoInfo) {
    $file = $logoInfo['file'];
    if (!$file) continue;
    $fullPath = $configPath . '/' . $logoInfo['folder'] . '/' . $file;
    if (file_exists($fullPath)) {
        $logoExists = true;
        $logoPath = BASE_URL . '/' . $logoInfo['folder'] . '/' . $file;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --navy: #0d1f3c;
            --navy-light: #1a3a5c;
            --gold: #f0b429;
            --gold-dark: #c49a1a;
            --success: #10b981;
            --danger: #ef4444;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
        }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 9px; 
            line-height: 1.4; 
            color: #333;
            background: <?php echo $print ? '#fff' : '#e5e7eb'; ?>;
        }
        
        .page {
            width: 210mm; 
            height: 297mm;
            margin: <?php echo $print ? '0' : '15px auto'; ?>; 
            padding: 0;
            background: #fff; 
            overflow: hidden;
            position: relative;
            <?php if (!$print): ?>
            box-shadow: 0 15px 40px -10px rgba(0,0,0,0.2);
            <?php endif; ?>
        }
        
        /* Header Section - Elegant */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 25px 15px;
            border-bottom: 3px solid var(--navy);
            background: linear-gradient(180deg, #fafbfc 0%, #fff 100%);
        }
        
        .company-block {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .logo-box {
            width: 55px;
            height: 55px;
            background: #fff;
            border: 2px solid var(--gold);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(240,180,41,0.15);
        }
        
        .logo-box img {
            max-width: 48px;
            max-height: 48px;
            object-fit: contain;
        }
        
        .logo-box .no-logo {
            font-size: 7px;
            color: var(--gray-400);
            text-align: center;
        }
        
        .company-info {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .company-info h1 {
            font-size: 18px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 2px;
            letter-spacing: 0.5px;
        }
        
        .company-info .tagline {
            font-size: 8px;
            color: var(--gold-dark);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        
        .company-contact {
            font-size: 8px;
            color: var(--gray-600);
            line-height: 1.5;
        }
        
        .company-contact .row {
            margin-bottom: 1px;
        }
        
        .invoice-header {
            text-align: right;
        }
        
        .invoice-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: 4px;
            margin-bottom: 4px;
        }
        
        .invoice-number {
            font-size: 11px;
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 6px;
        }
        
        .invoice-meta {
            font-size: 9px;
            color: var(--gray-500);
        }
        
        .invoice-meta .row {
            margin-bottom: 2px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 6px;
        }
        
        .status-draft { background: var(--gray-200); color: var(--gray-600); }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-paid { background: #d1fae5; color: #059669; }
        .status-partial { background: #fef3c7; color: #d97706; }
        .status-overdue { background: #fee2e2; color: #dc2626; }
        
        /* Content - Elegant Spacing */
        .content {
            padding: 18px 25px;
        }
        
        /* Bill To Section - Elegant */
        .parties-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .party-box {
            padding: 12px 15px;
            background: linear-gradient(135deg, var(--gray-50) 0%, #fff 100%);
            border-radius: 6px;
            border-left: 3px solid var(--gold);
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        
        .party-box h4 {
            font-size: 7px;
            font-weight: 700;
            color: var(--gold-dark);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        
        .party-box .name {
            font-size: 12px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 4px;
        }
        
        .party-box .info {
            font-size: 9px;
            color: var(--gray-600);
            line-height: 1.5;
        }
        
        /* Invoice Table - Elegant */
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .invoice-table th {
            background: linear-gradient(135deg, var(--navy), var(--navy-light));
            color: #fff;
            padding: 10px 12px;
            text-align: left;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .invoice-table th:first-child { border-radius: 5px 0 0 0; }
        .invoice-table th:last-child { border-radius: 0 5px 0 0; }
        
        .invoice-table td {
            padding: 12px;
            border-bottom: 1px solid var(--gray-200);
            font-size: 10px;
        }
        
        .term-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--gold), var(--gold-dark));
            color: var(--navy);
            font-size: 12px;
            font-weight: 800;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(240,180,41,0.3);
        }
        
        .item-title {
            font-weight: 600;
            color: var(--navy);
            font-size: 11px;
        }
        
        .item-subtitle {
            font-size: 8px;
            color: var(--gray-400);
            margin-top: 2px;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        /* Summary - Elegant */
        .summary-wrapper {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }
        
        .summary-table {
            width: 260px;
            background: var(--gray-50);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 14px;
            border-bottom: 1px solid var(--gray-200);
            font-size: 9px;
        }
        
        .summary-row .value.add { color: var(--success); font-weight: 600; }
        .summary-row .value.sub { color: var(--danger); font-weight: 600; }
        
        .summary-total {
            background: linear-gradient(135deg, var(--navy), var(--navy-light));
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .summary-total .label {
            color: rgba(255,255,255,0.8);
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .summary-total .value {
            color: var(--gold);
            font-size: 14px;
            font-weight: 800;
        }
        
        /* Amount in Words - Elegant */
        .amount-words {
            background: linear-gradient(135deg, rgba(240,180,41,0.08) 0%, rgba(240,180,41,0.03) 100%);
            border: 1px solid rgba(240,180,41,0.3);
            border-radius: 5px;
            padding: 10px 14px;
            margin-bottom: 12px;
        }
        
        .amount-words .label {
            font-size: 7px;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        
        .amount-words .text {
            font-size: 10px;
            font-weight: 600;
            color: var(--navy);
            font-style: italic;
        }
        
        /* Bank & Terms Row - Side by Side */
        .bottom-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 10px;
        }
        
        .bank-section {
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-50) 100%);
            border-radius: 6px;
            padding: 12px 14px;
        }
        
        .bank-section h5 {
            font-size: 7px;
            font-weight: 700;
            color: var(--gold-dark);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        
        .bank-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 4px;
        }
        
        .bank-item {
            font-size: 9px;
        }
        
        .bank-item span {
            color: var(--gray-500);
        }
        
        .bank-item strong {
            color: var(--navy);
        }
        
        .terms-section {
            padding: 12px 14px;
            background: var(--gray-50);
            border-radius: 6px;
        }
        
        .terms-section h5 {
            font-size: 7px;
            font-weight: 700;
            color: var(--gold-dark);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        
        .terms-section ul {
            font-size: 8px;
            color: var(--gray-500);
            margin-left: 12px;
            line-height: 1.5;
        }
        
        /* Notes - Elegant */
        .notes-section {
            background: linear-gradient(135deg, #fffbeb 0%, #fff 100%);
            border-left: 3px solid #f59e0b;
            padding: 10px 14px;
            border-radius: 0 6px 6px 0;
            margin-bottom: 10px;
        }
        
        .notes-section .label {
            font-size: 7px;
            font-weight: 700;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .notes-section .text {
            font-size: 9px;
            color: #78350f;
        }
        
        /* Signatures - Elegant */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            margin-top: 20px;
            padding: 0 30px;
        }
        
        .sig-block {
            text-align: center;
        }
        
        .sig-block .role {
            font-size: 9px;
            color: var(--gray-500);
            font-weight: 500;
            margin-bottom: 72px;
        }
        
        .sig-block .line {
            border-top: 1px solid var(--navy);
            padding-top: 8px;
        }
        
        .sig-block .name {
            font-size: 10px;
            font-weight: 700;
            color: var(--navy);
        }
        
        /* Footer - Elegant */
        .footer {
            background: linear-gradient(180deg, var(--gray-50) 0%, #fff 100%);
            padding: 12px 25px;
            text-align: center;
            font-size: 8px;
            color: var(--gray-400);
            border-top: 1px solid var(--gray-200);
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
        }
        
        /* Print Controls */
        .print-controls { 
            position: fixed; 
            top: 15px; 
            right: 15px; 
            display: flex; 
            gap: 8px; 
            z-index: 100;
        }
        
        .print-controls button, .print-controls a {
            padding: 10px 20px; 
            border: none; 
            border-radius: 6px;
            font-weight: 700; 
            font-size: 12px; 
            cursor: pointer;
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px;
            transition: all 0.2s;
        }
        
        .btn-print { 
            background: var(--navy); 
            color: #fff; 
            box-shadow: 0 3px 10px rgba(13,31,60,0.25);
        }
        
        .btn-back { 
            background: #fff; 
            color: var(--gray-600); 
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
            
            html, body { 
                width: 210mm; 
                height: 297mm; 
                margin: 0 !important; 
                padding: 0 !important; 
                background: #fff !important; 
            }
            
            .page { 
                width: 210mm !important; 
                height: 297mm !important;
                box-shadow: none !important; 
                margin: 0 !important; 
                padding: 0 !important;
                position: relative !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
            }
            
            .print-controls { display: none !important; }
            
            /* Preserve header styling */
            .header {
                background: linear-gradient(180deg, #fafbfc 0%, #fff 100%) !important;
                border-bottom: 3px solid var(--navy) !important;
                padding: 20px 25px 15px !important;
            }
            
            .logo-box {
                background: #fff !important;
                border: 2px solid var(--gold) !important;
                box-shadow: 0 2px 8px rgba(240,180,41,0.15) !important;
            }
            
            /* Preserve table header */
            .invoice-table th {
                background: var(--navy) !important;
                color: #fff !important;
            }
            
            /* Preserve summary total */
            .summary-total {
                background: var(--navy) !important;
            }
            
            .summary-total .label { color: rgba(255,255,255,0.8) !important; }
            .summary-total .value { color: var(--gold) !important; }
            
            /* Preserve badges */
            .term-badge {
                background: linear-gradient(135deg, var(--gold), var(--gold-dark)) !important;
                box-shadow: 0 2px 6px rgba(240,180,41,0.3) !important;
            }
            
            .status-badge { background: var(--gray-200) !important; }
            .status-paid { background: #d1fae5 !important; }
            .status-sent { background: #dbeafe !important; }
            .status-partial { background: #fef3c7 !important; }
            .status-overdue { background: #fee2e2 !important; }
            
            /* Preserve boxes */
            .party-box { 
                background: linear-gradient(135deg, var(--gray-50) 0%, #fff 100%) !important;
                border-left: 3px solid var(--gold) !important;
            }
            
            .amount-words {
                background: linear-gradient(135deg, rgba(240,180,41,0.08) 0%, rgba(240,180,41,0.03) 100%) !important;
            }
            
            .bank-section {
                background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-50) 100%) !important;
            }
            
            .notes-section {
                background: linear-gradient(135deg, #fffbeb 0%, #fff 100%) !important;
            }
            
            .footer { 
                position: absolute !important;
                bottom: 0 !important;
                background: linear-gradient(180deg, var(--gray-50) 0%, #fff 100%) !important;
            }
            
            @page { 
                size: A4 portrait; 
                margin: 0; 
            }
        }
    </style>
</head>
<body>
    <?php if (!$print): ?>
    <div class="print-controls">
        <a href="index-cqc.php" class="btn-back">← Back</a>
        <button class="btn-print" onclick="window.print()">Print Invoice</button>
    </div>
    <?php endif; ?>

    <div class="page">
        <!-- Header -->
        <div class="header">
            <div class="company-block">
                <div class="logo-box">
                    <?php if ($logoExists): ?>
                        <img src="<?php echo $logoPath; ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
                    <?php else: ?>
                        <div class="no-logo">LOGO</div>
                    <?php endif; ?>
                </div>
                <div class="company-info">
                    <h1><?php echo htmlspecialchars($companyName); ?></h1>
                    <div class="tagline"><?php echo htmlspecialchars($companyTagline); ?></div>
                    <div class="company-contact">
                        <div class="row">📍 <?php echo htmlspecialchars($fullAddress); ?></div>
                        <div class="row">📞 <?php echo htmlspecialchars($companyPhone); ?> | ✉️ <?php echo htmlspecialchars($companyEmail); ?></div>
                        <div class="row">NPWP: <?php echo htmlspecialchars($companyNPWP); ?></div>
                    </div>
                </div>
            </div>
            <div class="invoice-header">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                <div class="invoice-meta">
                    <div class="row"><strong>Date:</strong> <?php echo formatInvDate($invoice['invoice_date']); ?></div>
                    <?php if ($invoice['due_date']): ?>
                    <div class="row"><strong>Due:</strong> <?php echo formatInvDate($invoice['due_date']); ?></div>
                    <?php endif; ?>
                </div>
                <?php
                $statusLabels = [
                    'draft' => 'DRAFT',
                    'sent' => 'SENT',
                    'paid' => 'PAID',
                    'partial' => 'PARTIAL',
                    'overdue' => 'OVERDUE'
                ];
                ?>
                <span class="status-badge status-<?php echo $invoice['payment_status']; ?>">
                    <?php echo $statusLabels[$invoice['payment_status']] ?? strtoupper($invoice['payment_status']); ?>
                </span>
            </div>
        </div>
        
        <div class="content">
            <!-- Bill To / Project Info -->
            <div class="parties-row">
                <div class="party-box">
                    <h4>Bill To</h4>
                    <div class="name"><?php echo htmlspecialchars($invoice['client_name']); ?></div>
                    <div class="info">
                        <?php if ($invoice['client_phone']): ?>Phone: <?php echo htmlspecialchars($invoice['client_phone']); ?><br><?php endif; ?>
                        <?php if ($invoice['client_email']): ?>Email: <?php echo htmlspecialchars($invoice['client_email']); ?><br><?php endif; ?>
                        <?php if ($invoice['location']): ?>Address: <?php echo htmlspecialchars($invoice['location']); ?><?php endif; ?>
                    </div>
                </div>
                <div class="party-box">
                    <h4>Project</h4>
                    <div class="name"><?php echo htmlspecialchars($invoice['project_name']); ?></div>
                    <div class="info">
                        Code: <?php echo htmlspecialchars($invoice['project_code']); ?>
                        <?php if ($invoice['solar_capacity_kwp']): ?><br>Capacity: <?php echo number_format($invoice['solar_capacity_kwp'], 2); ?> kWp<?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Invoice Items -->
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 50px;" class="text-center">Term</th>
                        <th>Description</th>
                        <th style="width: 50px;" class="text-center">%</th>
                        <th style="width: 110px;" class="text-right">Contract Value</th>
                        <th style="width: 110px;" class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-center">
                            <span class="term-badge"><?php echo $invoice['termin_number']; ?></span>
                        </td>
                        <td>
                            <div class="item-title"><?php echo htmlspecialchars($invoice['description'] ?: 'Progress Payment Term ' . $invoice['termin_number']); ?></div>
                            <div class="item-subtitle">Project: <?php echo htmlspecialchars($invoice['project_name']); ?></div>
                        </td>
                        <td class="text-center" style="font-weight: 700; color: var(--gold-dark);">
                            <?php echo number_format($invoice['percentage'], 1); ?>%
                        </td>
                        <td class="text-right">IDR <?php echo number_format($invoice['contract_value'], 0, ',', '.'); ?></td>
                        <td class="text-right" style="font-weight: 700;">IDR <?php echo number_format($invoice['base_amount'], 0, ',', '.'); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Summary -->
            <div class="summary-wrapper">
                <div class="summary-table">
                    <div class="summary-row">
                        <span class="label">Subtotal (DPP)</span>
                        <span class="value">IDR <?php echo number_format($invoice['base_amount'], 0, ',', '.'); ?></span>
                    </div>
                    <?php if ($invoice['ppn_amount'] > 0): ?>
                    <div class="summary-row">
                        <span class="label">(+) VAT <?php echo number_format($invoice['ppn_percentage'], 1); ?>%</span>
                        <span class="value add">+ IDR <?php echo number_format($invoice['ppn_amount'], 0, ',', '.'); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($invoice['pph_amount'] > 0): ?>
                    <div class="summary-row">
                        <span class="label">(-) Income Tax <?php echo number_format($invoice['pph_percentage'], 1); ?>%</span>
                        <span class="value sub">- IDR <?php echo number_format($invoice['pph_amount'], 0, ',', '.'); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($invoice['retention_amount'] > 0): ?>
                    <div class="summary-row">
                        <span class="label">(-) Retention <?php echo number_format($invoice['retention_percentage'], 1); ?>%</span>
                        <span class="value sub">- IDR <?php echo number_format($invoice['retention_amount'], 0, ',', '.'); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="summary-total">
                        <span class="label">Total Due</span>
                        <span class="value">IDR <?php echo number_format($invoice['total_amount'], 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Amount in Words -->
            <div class="amount-words">
                <div class="label">Amount in Words</div>
                <div class="text"># <?php echo $totalInWords; ?> #</div>
            </div>
            
            <?php if ($invoice['notes']): ?>
            <div class="notes-section">
                <div class="label">Notes</div>
                <div class="text"><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div>
            </div>
            <?php endif; ?>
            
            <!-- Bank & Terms - Side by Side -->
            <div class="bottom-row">
                <?php if ($bankName || $bankAccount): ?>
                <div class="bank-section">
                    <h5>Payment Information</h5>
                    <div class="bank-grid">
                        <?php if ($bankName): ?><div class="bank-item"><span>Bank:</span> <strong><?php echo htmlspecialchars($bankName); ?></strong></div><?php endif; ?>
                        <?php if ($bankAccount): ?><div class="bank-item"><span>Account:</span> <strong><?php echo htmlspecialchars($bankAccount); ?></strong></div><?php endif; ?>
                        <?php if ($bankHolder): ?><div class="bank-item"><span>Name:</span> <strong><?php echo htmlspecialchars($bankHolder); ?></strong></div><?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div></div>
                <?php endif; ?>
                
                <div class="terms-section">
                    <h5>Terms & Conditions</h5>
                    <ul>
                        <li>Payment due within 14 days from invoice date</li>
                        <li>Include invoice number as payment reference</li>
                    </ul>
                </div>
            </div>
            
            <!-- Signatures -->
            <div class="signatures">
                <div class="sig-block">
                    <div class="role">Received By</div>
                    <div class="line">
                        <div class="name"><?php echo htmlspecialchars($invoice['client_name']); ?></div>
                    </div>
                </div>
                <div class="sig-block">
                    <div class="role">Authorized Signature</div>
                    <div class="line">
                        <div class="name"><?php echo htmlspecialchars($companyName); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            Thank you for your business. For inquiries: <?php echo htmlspecialchars($companyEmail); ?>
        </div>
    </div>

    <?php if ($print): ?>
    <script>window.onload = function() { window.print(); }</script>
    <?php endif; ?>
</body>
</html>
