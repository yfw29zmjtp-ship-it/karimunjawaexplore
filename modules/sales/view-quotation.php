<?php
/**
 * CQC Quotation - View & Print Quotation
 * Design matches the CQC official quotation format
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
    ensureCQCQuotationTable($pdo);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get quotation ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: index-cqc.php?tab=quotation');
    exit;
}

// Fetch quotation
$stmt = $pdo->prepare("SELECT * FROM cqc_quotations WHERE id = ?");
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    die("Quotation tidak ditemukan");
}

// Fetch items
$stmtItems = $pdo->prepare("SELECT * FROM cqc_quotation_items WHERE quotation_id = ? ORDER BY sort_order");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Get company settings
$bizDb = Database::getInstance();
$companyName = $bizDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")['setting_value'] ?? 'PT. CITRAQIAN CAHAYA ENJINIRING';
$companyAddress = $bizDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_address'")['setting_value'] ?? '';
$companyPhone = $bizDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_phone'")['setting_value'] ?? '';
$companyEmail = $bizDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_email'")['setting_value'] ?? '';

$pageTitle = "Quotation " . $quote['quote_number'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            font-size: 11px; 
            color: #333;
            background: #f5f5f5;
        }
        
        .print-container {
            max-width: 210mm;
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .quote-page {
            padding: 15mm 20mm;
            background: white;
        }
        
        /* Header */
        .header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
            border-bottom: 3px solid #0d1f3c;
            padding-bottom: 15px;
        }
        
        .logo-section {
            width: 80px;
            margin-right: 15px;
        }
        
        .logo-section img {
            width: 70px;
            height: auto;
        }
        
        .company-info {
            flex: 1;
            text-align: center;
        }
        
        .company-name {
            font-size: 16px;
            font-weight: 700;
            color: #0d1f3c;
            margin-bottom: 2px;
        }
        
        .company-tagline {
            font-size: 9px;
            color: #666;
            margin-bottom: 3px;
        }
        
        .company-services {
            font-size: 9px;
            font-weight: 600;
            color: #0d1f3c;
            margin-bottom: 5px;
        }
        
        .company-contact {
            font-size: 8px;
            color: #666;
            line-height: 1.4;
        }
        
        /* Quote Info Row */
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .info-left, .info-right {
            width: 48%;
        }
        
        .info-table {
            font-size: 10px;
        }
        
        .info-table td {
            padding: 2px 0;
            vertical-align: top;
        }
        
        .info-table td:first-child {
            width: 70px;
            font-weight: 500;
        }
        
        /* Greeting */
        .greeting {
            font-size: 10px;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .items-table th {
            background: #0d1f3c;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-size: 9px;
            font-weight: 600;
        }
        
        .items-table th:nth-child(1) { width: 30px; text-align: center; }
        .items-table th:nth-child(4) { text-align: center; }
        .items-table th:nth-child(5) { text-align: center; }
        .items-table th:nth-child(6) { text-align: right; }
        .items-table th:nth-child(7) { text-align: right; }
        
        .items-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 10px;
            vertical-align: top;
        }
        
        .items-table td:nth-child(1) { text-align: center; }
        .items-table td:nth-child(4) { text-align: center; }
        .items-table td:nth-child(5) { text-align: center; }
        .items-table td:nth-child(6) { text-align: right; }
        .items-table td:nth-child(7) { text-align: right; }
        
        /* Summary */
        .summary-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
        
        .summary-table {
            width: 250px;
        }
        
        .summary-table td {
            padding: 4px 8px;
            font-size: 10px;
        }
        
        .summary-table td:first-child {
            text-align: right;
            font-weight: 500;
        }
        
        .summary-table td:last-child {
            text-align: right;
            width: 100px;
        }
        
        .summary-table tr.total {
            background: #0d1f3c;
            color: white;
            font-weight: 700;
        }
        
        .summary-table tr.total td {
            padding: 6px 8px;
        }
        
        /* Terms */
        .terms-section {
            background: #f8f9fa;
            border: 1px solid #e5e5e5;
            padding: 12px;
            margin-bottom: 20px;
        }
        
        .terms-title {
            font-weight: 700;
            font-size: 10px;
            margin-bottom: 8px;
            color: #0d1f3c;
        }
        
        .terms-content {
            font-size: 9px;
            line-height: 1.6;
            white-space: pre-line;
        }
        
        /* Closing */
        .closing {
            font-size: 10px;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        /* Signature */
        .signature-section {
            margin-top: 30px;
        }
        
        .signature-title {
            font-size: 10px;
            font-weight: 500;
            margin-bottom: 60px;
        }
        
        .signature-name {
            font-size: 10px;
            font-weight: 700;
            text-decoration: underline;
        }
        
        .signature-position {
            font-size: 9px;
            color: #666;
        }
        
        /* Print Button */
        .print-actions {
            text-align: center;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin: 0 5px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-print {
            background: #0d1f3c;
            color: white;
        }
        
        .btn-back {
            background: #e5e5e5;
            color: #333;
        }
        
        @media print {
            body { background: white; }
            .print-container { box-shadow: none; margin: 0; }
            .print-actions { display: none; }
            .quote-page { padding: 10mm 15mm; }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="print-actions">
            <button class="btn btn-print" onclick="window.print()">🖨️ Print Quotation</button>
            <a href="index-cqc.php?tab=quotation" class="btn btn-back">← Kembali</a>
        </div>
        
        <div class="quote-page">
            <!-- Header -->
            <div class="header">
                <div class="logo-section">
                    <img src="<?php echo BASE_URL; ?>/assets/img/cqc-logo.png" alt="CQC Logo" onerror="this.src='<?php echo BASE_URL; ?>/assets/img/logo.png'">
                </div>
                <div class="company-info">
                    <div class="company-name">PT. CITRAQIAN CAHAYA ENJINIRING</div>
                    <div class="company-tagline">"CQC Embark"</div>
                    <div class="company-services">Electrical Service, Automation and Maintenance Services<br>General Supplier Mechanical and Electrical</div>
                    <div class="company-contact">
                        Head Office: Siyonoharjo, Anggaswangi, Godean, Sleman, Yogyakarta 55511, Telp:0813 5882 6474, Email: adha@cqcenjiniring.com<br>
                        Branch Office: Mega Regency Blok C5 No.1, Telp 031 5882 6474, Email: adhad@cqcenjiniring.com<br>
                        <a href="https://cqcenjiniring.com" style="color: #0d1f3c;">https://cqcenjiniring.com</a>
                    </div>
                </div>
            </div>
            
            <!-- Quote Info -->
            <div class="info-row">
                <div class="info-left">
                    <table class="info-table">
                        <tr>
                            <td>Quote No.</td>
                            <td>: <strong><?php echo htmlspecialchars($quote['quote_number']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Date</td>
                            <td>: <?php echo date('d F Y', strtotime($quote['quote_date'])); ?></td>
                        </tr>
                        <tr>
                            <td>From</td>
                            <td>: Adi Irmayadi</td>
                        </tr>
                        <tr>
                            <td>Phone</td>
                            <td>: 0813 5882 6474</td>
                        </tr>
                        <tr>
                            <td>Email</td>
                            <td>: cqcenjiniring@gmail.com</td>
                        </tr>
                    </table>
                </div>
                <div class="info-right">
                    <table class="info-table">
                        <tr>
                            <td>To</td>
                            <td>: <strong><?php echo htmlspecialchars($quote['client_name']); ?></strong></td>
                        </tr>
                        <?php if ($quote['client_attn']): ?>
                        <tr>
                            <td>Attn</td>
                            <td>: <?php echo htmlspecialchars($quote['client_attn']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Phone</td>
                            <td>: <?php echo htmlspecialchars($quote['client_phone'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td>Email</td>
                            <td>: <?php echo htmlspecialchars($quote['client_email'] ?: '-'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Greeting -->
            <div class="greeting">
                Dear Sir/Madam,<br><br>
                Thank you for your opportunity to quote on your requirements. We are pleased to offer as follows:
            </div>
            
            <!-- Items Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>DESCRIPTION</th>
                        <th>REMARKS</th>
                        <th>QTY</th>
                        <th>UNIT</th>
                        <th>PRICE</th>
                        <th>TOTAL PRICE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo nl2br(htmlspecialchars($item['description'])); ?></td>
                        <td><?php echo htmlspecialchars($item['remarks'] ?: 'CQC'); ?></td>
                        <td><?php echo number_format($item['quantity'], 0); ?></td>
                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td><?php echo number_format($item['unit_price'], 0, ',', '.'); ?></td>
                        <td><?php echo number_format($item['amount'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Summary -->
            <div class="summary-section">
                <table class="summary-table">
                    <tr>
                        <td>PRICES :</td>
                        <td><?php echo number_format($quote['subtotal'], 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td>DISC :</td>
                        <td><?php echo number_format($quote['discount_amount'], 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td>TOTAL PRICE :</td>
                        <td><?php echo number_format($quote['subtotal'] - $quote['discount_amount'], 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td>TAX <?php echo $quote['ppn_percentage']; ?>% :</td>
                        <td><?php echo number_format($quote['ppn_amount'], 0, ',', '.'); ?></td>
                    </tr>
                    <tr class="total">
                        <td>GRAND TOTAL :</td>
                        <td><?php echo number_format($quote['total_amount'], 0, ',', '.'); ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Terms & Conditions -->
            <?php if ($quote['terms_conditions']): ?>
            <div class="terms-section">
                <div class="terms-title">TERM AND CONDITION</div>
                <div class="terms-content"><?php echo htmlspecialchars($quote['terms_conditions']); ?></div>
            </div>
            <?php endif; ?>
            
            <!-- Closing -->
            <?php if ($quote['notes']): ?>
            <div class="closing">
                <?php echo nl2br(htmlspecialchars($quote['notes'])); ?>
            </div>
            <?php endif; ?>
            
            <!-- Signature -->
            <div class="signature-section">
                <div class="signature-title">Hormat Kami,</div>
                <div class="signature-name">ADI IRMAYADI</div>
                <div class="signature-position">DIREKTUR</div>
            </div>
        </div>
    </div>
</body>
</html>
