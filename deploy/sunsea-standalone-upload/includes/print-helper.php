<?php
/**
 * PRINT HELPER - Elegant Print Templates for Multi-Business System
 */

/**
 * Generate elegant print header with logo and company info
 */
function printHeader($db, $displayCompanyName, $businessIcon, $businessType, $title, $period = '') {
    // Prioritize business-specific invoice_logo, fallback to global invoice_logo, then company_logo
    $logoPath = null;
    $businessId = ACTIVE_BUSINESS_ID ?? '';
    
    // Try business-specific invoice logo first
    if ($businessId) {
        $businessInvoiceLogoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", 
            ['key' => 'invoice_logo_' . $businessId]);
        if ($businessInvoiceLogoResult && !empty($businessInvoiceLogoResult['setting_value'])) {
            $logoPath = $businessInvoiceLogoResult['setting_value'];
        }
    }
    
    // Fallback to global invoice_logo
    if (!$logoPath) {
        $invoiceLogoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'invoice_logo'");
        if ($invoiceLogoResult && !empty($invoiceLogoResult['setting_value'])) {
            $logoPath = $invoiceLogoResult['setting_value'];
        }
    }
    
    // Fallback to company_logo
    if (!$logoPath) {
        $companyLogoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_logo'");
        if ($companyLogoResult && !empty($companyLogoResult['setting_value'])) {
            $logoPath = $companyLogoResult['setting_value'];
        }
    }
    
    // Convert relative path to absolute if needed
    $isCloudinaryUrl = ($logoPath && strpos($logoPath, 'http') === 0);
    if ($logoPath && !$isCloudinaryUrl) {
        $testPath = __DIR__ . '/../' . ltrim($logoPath, '/');
        if (file_exists($testPath)) {
            $logoPath = $testPath;
        }
    }
    
    ob_start();
    ?>
    <div class="print-header">
        <div class="print-header-left">
            <?php if ($isCloudinaryUrl): ?>
                <img src="<?php echo htmlspecialchars($logoPath); ?>" class="print-logo" alt="Logo">
            <?php elseif ($logoPath && file_exists($logoPath)): ?>
                <img src="<?php echo $logoPath; ?>" class="print-logo" alt="Logo">
            <?php else: ?>
                <div style="width: 60px; height: 60px; margin: 0 auto; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 0.35rem; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: white;">
                    <?php echo $businessIcon; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="print-header-center">
            <h1 class="print-company-name"><?php echo htmlspecialchars($displayCompanyName); ?></h1>
            <h2 class="print-title"><?php echo htmlspecialchars($title); ?></h2>
            <?php if (!empty($period)): ?>
                <p class="print-period"><?php echo htmlspecialchars($period); ?></p>
            <?php endif; ?>
        </div>
        
        <div class="print-header-right">
            <div style="font-size: 0.75rem; color: #64748b; line-height: 1.3;">
                <div><?php echo date('d/m/Y'); ?></div>
                <div><?php echo date('H:i'); ?></div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Generate print footer with signature lines
 */
function printFooter($userName = '') {
    if (empty($userName)) {
        $userName = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Admin';
    }
    
    ob_start();
    ?>
    <div class="print-footer">
        <div class="print-footer-item">
            <div class="print-footer-label">Diperiksa Oleh</div>
            <div class="print-footer-line"></div>
            <div class="print-footer-text"><?php echo htmlspecialchars($userName); ?></div>
        </div>
        <div class="print-footer-item">
            <div class="print-footer-label">Disetujui Oleh</div>
            <div class="print-footer-line"></div>
            <div class="print-footer-text">Pimpinan</div>
        </div>
        <div class="print-footer-item">
            <div class="print-footer-label">Tanggal Persetujuan</div>
            <div class="print-footer-line"></div>
            <div class="print-footer-text">_______________</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get elegant print CSS
 */
function getPrintCSS() {
    ob_start();
    ?>
<style>
/* ===== ELEGANT PRINT STYLES - COMPACT FOR 1 PAGE ===== */
@media print {
    * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        color-adjust: exact;
    }
    
    body {
        background: white;
        margin: 0;
        padding: 0;
    }
    
    /* Hide non-print elements */
    .sidebar, .page-header, button, .btn, .table-actions, .table-header > div:last-child, 
    form, a[href*="add"], a[href*="logs"], [onclick*="print"] {
        display: none !important;
    }
    
    /* Main content */
    .main-content, .content-wrapper, .page-content {
        width: 100%;
        padding: 0;
        margin: 0;
        background: white;
    }
    
    /* Print header - COMPACT */
    .print-header {
        display: table;
        width: 100%;
        margin-bottom: 0.75rem;
        border-bottom: 2px solid #1e293b;
        padding-bottom: 0.75rem;
    }
    
    .print-header-left {
        display: table-cell;
        width: 12%;
        vertical-align: middle;
        text-align: center;
    }
    
    .print-header-center {
        display: table-cell;
        width: 72%;
        vertical-align: middle;
        text-align: center;
        padding: 0 1rem;
    }
    
    .print-header-right {
        display: table-cell;
        width: 16%;
        vertical-align: middle;
        text-align: right;
    }
    
    .print-logo {
        width: 60px;
        height: 60px;
        object-fit: contain;
        margin: 0 auto;
    }
    
    .print-company-name {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 0.15rem 0;
        letter-spacing: -0.5px;
    }
    
    .print-company-type {
        font-size: 0.85rem;
        color: #64748b;
        margin: 0;
        font-weight: 500;
        display: none;
    }
    
    .print-title {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0.25rem 0 0.25rem 0;
        text-decoration: underline;
        text-decoration-color: #6366f1;
        text-underline-offset: 0.35rem;
    }
    
    .print-period {
        font-size: 0.8rem;
        color: #475569;
        text-align: center;
        margin: 0.15rem 0 0 0;
    }
    
    /* Summary cards - MINIMAL */
    .print-summary {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
        page-break-inside: avoid;
    }
    
    .print-summary-card {
        padding: 0.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 0.35rem;
        background: #f8fafc;
        text-align: center;
    }
    
    .print-summary-label {
        font-size: 0.7rem;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 0.25rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    
    .print-summary-value {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
    }
    
    .print-summary-value.income {
        color: #059669;
    }
    
    .print-summary-value.expense {
        color: #dc2626;
    }
    
    .print-summary-value.balance {
        color: #1e40af;
    }
    
    /* Table styling */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0.5rem;
        page-break-inside: avoid;
    }
    
    thead {
        background: #1e293b;
        color: white;
    }
    
    th {
        padding: 0.5rem;
        text-align: left;
        font-weight: 700;
        font-size: 0.8rem;
        border: 1px solid #0f172a;
        letter-spacing: 0.3px;
    }
    
    th:last-child {
        text-align: right;
    }
    
    td {
        padding: 0.4rem 0.5rem;
        border: 1px solid #cbd5e1;
        font-size: 0.8rem;
    }
    
    tbody tr:nth-child(odd) {
        background: #f8fafc;
    }
    
    tbody tr:nth-child(even) {
        background: white;
    }
    
    .badge {
        display: inline-block;
        padding: 0.2rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .badge.income {
        background: #d1fae5;
        color: #065f46;
    }
    
    .badge.expense {
        background: #fee2e2;
        color: #991b1b;
    }
    
    td[style*="text-align: right"] {
        text-align: right !important;
        font-weight: 600;
    }
    
    /* Print footer - MINIMAL */
    .print-footer {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #cbd5e1;
        display: flex;
        justify-content: space-around;
        text-align: center;
        page-break-inside: avoid;
    }
    
    .print-footer-item {
        flex: 1;
    }
    
    .print-footer-label {
        font-size: 0.75rem;
        color: #64748b;
        margin-bottom: 1rem;
        font-weight: 600;
    }
    
    .print-footer-line {
        border-top: 1px solid #0f172a;
        height: 1px;
        margin-bottom: 0.3rem;
    }
    
    .print-footer-text {
        font-size: 0.75rem;
        color: #0f172a;
        font-weight: 600;
    }
    
    .print-notes {
        margin-top: 0.75rem;
        padding: 0.5rem;
        background: #f1f5f9;
        border-left: 3px solid #6366f1;
        font-size: 0.75rem;
        color: #334155;
    }
    
    /* Page break */
    @page {
        margin: 0.4in;
        size: A4;
    }
}
</style>
    <?php
    return ob_get_clean();
}

/**
 * Convert number to Indonesian words (terbilang)
 */
function terbilang($nilai) {
    $nilai = abs((int)$nilai);
    $huruf = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
    $temp = "";
    
    if ($nilai < 12) {
        $temp = " " . $huruf[$nilai];
    } else if ($nilai < 20) {
        $temp = terbilang($nilai - 10) . " Belas";
    } else if ($nilai < 100) {
        $temp = terbilang((int)($nilai / 10)) . " Puluh" . terbilang($nilai % 10);
    } else if ($nilai < 200) {
        $temp = " Seratus" . terbilang($nilai - 100);
    } else if ($nilai < 1000) {
        $temp = terbilang((int)($nilai / 100)) . " Ratus" . terbilang($nilai % 100);
    } else if ($nilai < 2000) {
        $temp = " Seribu" . terbilang($nilai - 1000);
    } else if ($nilai < 1000000) {
        $temp = terbilang((int)($nilai / 1000)) . " Ribu" . terbilang($nilai % 1000);
    } else if ($nilai < 1000000000) {
        $temp = terbilang((int)($nilai / 1000000)) . " Juta" . terbilang($nilai % 1000000);
    } else if ($nilai < 1000000000000) {
        $temp = terbilang((int)($nilai / 1000000000)) . " Milyar" . terbilang($nilai % 1000000000);
    } else if ($nilai < 1000000000000000) {
        $temp = terbilang((int)($nilai / 1000000000000)) . " Trilyun" . terbilang($nilai % 1000000000000);
    }
    
    return trim($temp);
}

?>
