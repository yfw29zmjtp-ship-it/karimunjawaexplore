<?php
/**
 * Report PDF Helper Functions
 * Generate elegant PDF reports with company info, logo, and professional styling
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Get company information from settings
 */
function getCompanyInfo() {
    $db = Database::getInstance();
    $businessId = ACTIVE_BUSINESS_ID ?? '';
    
    $companyName = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
    $companyLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_logo'");
    
    // Try business-specific invoice logo first
    $invoiceLogo = null;
    if ($businessId) {
        $businessInvoiceLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", 
            ['key' => 'invoice_logo_' . $businessId]);
        if ($businessInvoiceLogo && isset($businessInvoiceLogo['setting_value'])) {
            $invoiceLogo = $businessInvoiceLogo['setting_value'];
        }
    }
    
    // Fallback to global invoice_logo
    if (!$invoiceLogo) {
        $globalInvoiceLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'invoice_logo'");
        if ($globalInvoiceLogo && isset($globalInvoiceLogo['setting_value'])) {
            $invoiceLogo = $globalInvoiceLogo['setting_value'];
        }
    }
    
    $companyAddress = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_address'");
    $companyPhone = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_phone'");
    $companyEmail = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_email'");
    
    return [
        'name' => ($companyName && isset($companyName['setting_value'])) ? $companyName['setting_value'] : BUSINESS_NAME,
        'logo' => ($companyLogo && isset($companyLogo['setting_value'])) ? $companyLogo['setting_value'] : null,
        'invoice_logo' => $invoiceLogo,
        'address' => ($companyAddress && isset($companyAddress['setting_value'])) ? $companyAddress['setting_value'] : '',
        'phone' => ($companyPhone && isset($companyPhone['setting_value'])) ? $companyPhone['setting_value'] : '',
        'email' => ($companyEmail && isset($companyEmail['setting_value'])) ? $companyEmail['setting_value'] : '',
        'icon' => BUSINESS_ICON ?? '🏢',
        'color' => BUSINESS_COLOR ?? '#3b82f6'
    ];
}

/**
 * Generate Report Header HTML with Logo and Company Info
 */
function generateReportHeader($title, $subtitle = '', $dateRange = '', $logoPath = null) {
    $company = getCompanyInfo();
    
    // Determine logo to display - use provided path or default
    $displayLogo = $logoPath ?? ($company['invoice_logo'] ?? $company['logo']);
    $logoHtml = '';
    
    // Logo should be a browser-accessible URL, not a filesystem path
    if ($displayLogo && strpos($displayLogo, 'http') === 0) {
        // Cloudinary or external URL - use as-is
        $logoHtml = '<img src="' . htmlspecialchars($displayLogo) . '" alt="Logo" style="width: 120px; height: 120px; object-fit: contain;">';
    } elseif ($displayLogo && strpos($displayLogo, '/') === 0) {
        // Absolute web path - use as-is
        $logoHtml = '<img src="' . htmlspecialchars($displayLogo) . '" alt="Logo" style="width: 120px; height: 120px; object-fit: contain;">';
    } elseif ($displayLogo) {
        // Relative path - convert to full URL
        $localPath = __DIR__ . '/../' . ltrim($displayLogo, '/');
        if (file_exists($localPath)) {
            $logoUrl = (defined('BASE_URL') ? BASE_URL : '') . '/' . ltrim($displayLogo, '/');
            $logoHtml = '<img src="' . htmlspecialchars($logoUrl) . '" alt="Logo" style="width: 120px; height: 120px; object-fit: contain;">';
        } else {
            $logoHtml = '<div style="width: 120px; height: 120px; font-size: 64px; display: flex; align-items: center; justify-content: center;">' . $company['icon'] . '</div>';
        }
    } else {
        // Fallback to icon emoji
        $logoHtml = '<div style="width: 120px; height: 120px; font-size: 64px; display: flex; align-items: center; justify-content: center;">' . $company['icon'] . '</div>';
    }
    
    $html = '
    <div style="display: flex; gap: 1rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid ' . $company['color'] . '; align-items: center;">
        <div style="flex-shrink: 0;">
            ' . $logoHtml . '
        </div>
        <div style="flex: 1;">
            <div style="font-size: 22px; font-weight: 800; color: ' . $company['color'] . '; margin-bottom: 0.15rem; letter-spacing: -0.3px;">
                ' . htmlspecialchars($company['name']) . '
            </div>
            <div style="font-size: 9px; color: #666; line-height: 1.4;">
                ' . ($company['address'] ? htmlspecialchars($company['address']) . '<br>' : '') . '
                ' . ($company['phone'] ? 'Tel: ' . htmlspecialchars($company['phone']) . ' ' : '') . '
                ' . ($company['email'] ? 'Email: ' . htmlspecialchars($company['email']) : '') . '
            </div>
        </div>
        <div style="text-align: right; min-width: 160px;">
            <div style="font-size: 10px; color: #666;">
                <div style="margin-bottom: 0.3rem;">
                    <strong>Laporan:</strong><br>
                    <span style="font-size: 14px; font-weight: 700; color: ' . $company['color'] . '; display: block;">
                        ' . htmlspecialchars($title) . '
                    </span>
                </div>
                ' . ($subtitle ? '<div style="color: #999; font-size: 9px; margin-bottom: 0.3rem;">' . htmlspecialchars($subtitle) . '</div>' : '') . '
                ' . ($dateRange ? '<div style="color: #666; font-weight: 600; font-size: 9px;">' . htmlspecialchars($dateRange) . '</div>' : '') . '
            </div>
        </div>
    </div>
    ';
    
    return $html;
}

/**
 * Generate Summary Card HTML for Print
 */
function generateSummaryCard($label, $value, $color = '#10b981', $icon = '') {
    return '
    <div style="background: linear-gradient(135deg, ' . $color . '10 0%, ' . $color . '05 100%); border: 1px solid ' . $color . '; border-radius: 4px; padding: 0.4rem 0.5rem; text-align: center; page-break-inside: avoid;">
        <div style="font-size: 7px; color: #666; text-transform: uppercase; font-weight: 600; letter-spacing: 0.2px; margin-bottom: 0.15rem;">
            ' . $icon . ' ' . htmlspecialchars($label) . '
        </div>
        <div style="font-size: 13px; font-weight: 800; color: ' . $color . '; line-height: 1.1;">
            ' . htmlspecialchars($value) . '
        </div>
    </div>
    ';
}

/**
 * Generate Report Footer with timestamp and page info
 */
function generateReportFooter($userName = '') {
    $company = getCompanyInfo();
    $printDate = date('d F Y, H:i:s');
    $currentYear = date('Y');
    
    // Get current user if not provided
    if (empty($userName)) {
        if (isset($_SESSION['user_full_name'])) {
            $userName = $_SESSION['user_full_name'];
        } elseif (isset($_SESSION['user_name'])) {
            $userName = $_SESSION['user_name'];
        } else {
            $userName = 'System';
        }
    }
    
    return '
    <div style="margin-top: 1rem; padding-top: 0.5rem; border-top: 1.5px solid #e5e7eb;">
        <table style="width: 100%; font-size: 7.5px; color: #666;">
            <tr>
                <td style="text-align: left; padding: 0.3rem 0;">
                    <div style="font-weight: 600; color: #333; margin-bottom: 0.15rem;">💼 Dicetak oleh:</div>
                    <div>' . htmlspecialchars($userName) . '</div>
                </td>
                <td style="text-align: center; padding: 0.3rem 0;">
                    <div style="font-weight: 600; color: #333; margin-bottom: 0.15rem;">📅 Tanggal Cetak:</div>
                    <div>' . $printDate . '</div>
                </td>
                <td style="text-align: right; padding: 0.3rem 0;">
                    <div style="font-weight: 600; color: #333; margin-bottom: 0.15rem;">👨‍💻 Developer:</div>
                    <div>Arief_adfsystem management © ' . $currentYear . '</div>
                </td>
            </tr>
        </table>
        <div style="text-align: center; padding-top: 0.3rem; border-top: 1px solid #f3f4f6; font-size: 7px; color: #999; margin-top: 0.3rem;">
            ' . htmlspecialchars($company['name']) . ' - Sistem Manajemen Keuangan
        </div>
    </div>
    ';
}

/**
 * Generate Summary Table HTML
 */
function generateSummaryTable($data) {
    $html = '
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: 12px;">
        <thead>
            <tr style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 700;">
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #d1d5db;">Periode</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Pemasukan</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Pengeluaran</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Net Balance</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #d1d5db;">Transaksi</th>
            </tr>
        </thead>
        <tbody>
    ';
    
    $rowCount = 0;
    foreach ($data as $row) {
        $bgColor = ($rowCount % 2 === 0) ? '#f9fafb' : '#ffffff';
        $netColor = ($row['net_balance'] >= 0) ? '#10b981' : '#ef4444';
        
        $html .= '
            <tr style="background: ' . $bgColor . ';">
                <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb;">' . htmlspecialchars($row['period']) . '</td>
                <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #10b981; font-weight: 600;">
                    Rp ' . number_format($row['income'], 0, ',', '.') . '
                </td>
                <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #ef4444; font-weight: 600;">
                    Rp ' . number_format($row['expense'], 0, ',', '.') . '
                </td>
                <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: right; color: ' . $netColor . '; font-weight: 700;">
                    Rp ' . number_format($row['net_balance'], 0, ',', '.') . '
                </td>
                <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: center; color: #666;">
                    ' . $row['transaction_count'] . '
                </td>
            </tr>
        ';
        $rowCount++;
    }
    
    $html .= '
        </tbody>
    </table>
    ';
    
    return $html;
}

/**
 * Generate Signature Section for printed reports
 */
function generateSignatureSection() {
    $company = getCompanyInfo();
    
    return '
    <div style="margin-top: 0.2rem; page-break-inside: avoid;">
        <table style="width: 100%; font-size: 6px;">
            <tr>
                <td style="width: 33%; text-align: center;">
                    <div style="border-top: 0.8px solid #000; padding-top: 0.1rem; min-height: 20px;"></div>
                </td>
                <td style="width: 34%;"></td>
                <td style="width: 33%; text-align: center;">
                    <div style="border-top: 0.8px solid #000; padding-top: 0.1rem; min-height: 20px;"></div>
                </td>
            </tr>
        </table>
    </div>
    ';
}

/**
 * Get Print CSS for reports
 */
function getReportPrintCSS() {
    return '
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @media print {
            html, body {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
            }
            
            body {
                font-family: "Segoe UI", "Trebuchet MS", sans-serif;
                font-size: 11px;
                color: #333;
                line-height: 1.5;
            }
            
            .report-container {
                width: 100%;
                margin: 0 auto;
                padding: 20mm;
                background: white;
            }
            
            .sidebar, .top-bar, .btn, form, .filter-section, .no-print {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            
            h1, h2, h3 {
                page-break-after: avoid;
            }
            
            table {
                page-break-inside: avoid;
            }
            
            tr {
                page-break-inside: avoid;
            }
            
            .page-break {
                page-break-after: always;
            }
            
            .card {
                page-break-inside: avoid;
                border: none;
                background: white;
                box-shadow: none;
            }
            
            a {
                color: #000;
                text-decoration: none;
            }
            
            img {
                max-width: 100%;
                height: auto;
            }
        }
    </style>
    ';
}
?>
