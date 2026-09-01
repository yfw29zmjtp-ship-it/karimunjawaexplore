<?php
// modules/payroll/print-slip.php - ELEGANT SALARY SLIP 2027
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/print-helper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$slip_id = $_GET['id'] ?? 0;

// Get slip with employee bank details and phone
$slip = $db->fetchOne(
    "
    SELECT s.*, p.period_label, p.period_month, p.period_year,
           e.bank_name, e.bank_account, e.employee_code, e.department, e.phone
    FROM payroll_slips s 
    JOIN payroll_periods p ON s.period_id = p.id 
    LEFT JOIN payroll_employees e ON s.employee_id = e.id
    WHERE s.id = ?",
    [$slip_id]
);

if (!$slip) {
    die("Slip gaji tidak ditemukan.");
}

$businessName = BUSINESS_NAME;

// Get business logo from invoice_logo setting (PDF logo in Settings > Report Settings)
$logoPath = null;
$businessId = ACTIVE_BUSINESS_ID ?? '';

// Priority 1: invoice_logo_[businessId] - from Report Settings
if ($businessId) {
    $logoResult = $db->fetchOne(
        "SELECT setting_value FROM settings WHERE setting_key = ?",
        ['invoice_logo_' . $businessId]
    );
    if ($logoResult && !empty($logoResult['setting_value'])) {
        $logoVal = $logoResult['setting_value'];
        $logoPath = (strpos($logoVal, 'http') === 0) ? $logoVal : 'uploads/logos/' . $logoVal;
    }
}

// Priority 2: Global invoice_logo
if (!$logoPath) {
    $logoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'invoice_logo'");
    if ($logoResult && !empty($logoResult['setting_value'])) {
        $logoVal = $logoResult['setting_value'];
        $logoPath = (strpos($logoVal, 'http') === 0) ? $logoVal : 'uploads/logos/' . $logoVal;
    }
}

// Priority 3: company_logo (this one usually has full path)
if (!$logoPath) {
    $logoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_logo'");
    if ($logoResult && !empty($logoResult['setting_value'])) {
        $logoPath = $logoResult['setting_value'];
    }
}

// Build full logo URL
$logoUrl = '';
if ($logoPath) {
    // Check if path already contains http
    if (strpos($logoPath, 'http') === 0) {
        $logoUrl = $logoPath;
    } else {
        $logoUrl = BASE_URL . '/' . ltrim($logoPath, '/');
    }
}

// Prepare WhatsApp number (remove leading 0, add 62)
$waNumber = '';
if (!empty($slip['phone'])) {
    $phone = preg_replace('/[^0-9]/', '', $slip['phone']);
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    } elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    $waNumber = $phone;
}

// Format period date (must be before WhatsApp message)
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$periodText = $months[$slip['period_month']] . ' ' . $slip['period_year'];

// WhatsApp message
$waMessage = urlencode("Halo " . $slip['employee_name'] . ",\n\nBerikut slip gaji Anda untuk periode " . $periodText . ":\n\nTotal Gaji Bersih: Rp " . number_format($slip['net_salary'], 0, ',', '.') . "\n\nTerima kasih.");
$waLink = $waNumber ? "https://wa.me/{$waNumber}?text={$waMessage}" : '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Slip Gaji - <?php echo htmlspecialchars($slip['employee_name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 10pt;
            color: #1e293b;
            background: #f1f5f9;
            padding: 20px;
        }

        .slip-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Header with gradient */
        .slip-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 25px 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .slip-logo {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .slip-logo img {
            max-width: 50px;
            max-height: 50px;
            object-fit: contain;
        }

        .slip-logo-text {
            font-size: 24px;
            font-weight: 700;
        }

        .slip-header-info {
            flex: 1;
        }

        .slip-header-info h1 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .slip-header-info p {
            font-size: 11px;
            opacity: 0.9;
        }

        .slip-period {
            text-align: right;
        }

        .slip-period-label {
            font-size: 10px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .slip-period-value {
            font-size: 16px;
            font-weight: 700;
            margin-top: 2px;
        }

        /* Body */
        .slip-body {
            padding: 25px 30px;
        }

        /* Employee Info Card */
        .emp-card {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .emp-info {
            flex: 1;
        }

        .emp-name {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .emp-position {
            font-size: 11px;
            color: #64748b;
        }

        .emp-code {
            font-size: 10px;
            color: #94a3b8;
            font-family: 'SF Mono', Monaco, monospace;
        }

        /* Table Sections */
        .section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e2e8f0;
        }

        .salary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .salary-section {
            background: #fafafa;
            border-radius: 8px;
            padding: 15px;
        }

        .salary-section.deductions {
            background: #fef2f2;
        }

        .salary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 10pt;
        }

        .salary-row:not(:last-child) {
            border-bottom: 1px dashed #e2e8f0;
        }

        .salary-section.deductions .salary-row:not(:last-child) {
            border-bottom: 1px dashed #fecaca;
        }

        .salary-label {
            color: #64748b;
        }

        .salary-value {
            font-weight: 600;
            font-family: 'SF Mono', Monaco, monospace;
            color: #1e293b;
        }

        .salary-section.deductions .salary-value {
            color: #dc2626;
        }

        .salary-total {
            font-weight: 700;
            padding-top: 10px;
            margin-top: 6px;
            border-top: 2px solid #e2e8f0;
        }

        .salary-section.deductions .salary-total {
            border-top: 2px solid #fecaca;
        }

        /* Net Salary Box */
        .net-salary-box {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 10px;
            padding: 20px;
            color: #fff;
            margin-bottom: 20px;
        }

        .net-salary-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }

        .net-salary-amount {
            font-size: 26px;
            font-weight: 700;
            margin: 8px 0;
            font-family: 'SF Mono', Monaco, monospace;
        }

        .net-salary-words {
            font-size: 10px;
            opacity: 0.85;
            font-style: italic;
        }

        /* Bank Transfer Info */
        .bank-info {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .bank-info-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #3b82f6;
            margin-bottom: 10px;
        }

        .bank-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .bank-item-label {
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
        }

        .bank-item-value {
            font-size: 12px;
            font-weight: 600;
            color: #1e3a8a;
            font-family: 'SF Mono', Monaco, monospace;
        }

        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 30px;
            text-align: center;
        }

        .signature-box {
            padding-top: 50px;
        }

        .signature-line {
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            font-size: 10px;
            color: #64748b;
        }

        .signature-title {
            font-size: 9px;
            color: #94a3b8;
            margin-bottom: 5px;
        }

        /* Footer */
        .slip-footer {
            background: #f8fafc;
            padding: 15px 30px;
            text-align: center;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .slip-container {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }

            .no-print {
                display: none;
            }

            @page {
                margin: 0.5in;
                size: A4;
            }
        }

        /* Print Button */
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            z-index: 100;
        }

        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.5);
        }

        .btn-group {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 100;
        }

        .btn-action {
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            justify-content: center;
            transition: all 0.2s;
        }

        .btn-print {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-pdf {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .btn-wa {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.4);
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }
    </style>
</head>

<body>

    <div class="btn-group no-print">
        <button class="btn-action btn-print" onclick="window.print()">
            🖨️ Cetak Slip Gaji
        </button>
        <button class="btn-action btn-pdf" onclick="savePDF()">
            📄 Save to PDF
        </button>
        <?php if ($waLink): ?>
            <a href="<?php echo $waLink; ?>" target="_blank" class="btn-action btn-wa">
                💬 Kirim via WhatsApp
            </a>
        <?php endif; ?>
    </div>

    <div class="slip-container">
        <!-- Header -->
        <div class="slip-header">
            <div class="slip-logo">
                <?php if ($logoUrl): ?>
                    <img src="<?php echo $logoUrl; ?>" alt="Logo" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                    <span class="slip-logo-text" style="display:none;">💼</span>
                <?php else: ?>
                    <span class="slip-logo-text">💼</span>
                <?php endif; ?>
            </div>
            <div class="slip-header-info">
                <h1><?php echo htmlspecialchars($businessName); ?></h1>
                <p>SLIP GAJI KARYAWAN</p>
            </div>
            <div class="slip-period">
                <div class="slip-period-label">Periode</div>
                <div class="slip-period-value"><?php echo $periodText; ?></div>
            </div>
        </div>

        <!-- Body -->
        <div class="slip-body">

            <!-- Employee Info -->
            <div class="emp-card">
                <div class="emp-info">
                    <div class="emp-name"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                    <div class="emp-position"><?php echo htmlspecialchars($slip['position']); ?></div>
                    <?php if (!empty($slip['employee_code'])): ?>
                        <div class="emp-code">ID: <?php echo htmlspecialchars($slip['employee_code']); ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($slip['department'])): ?>
                    <div style="text-align: right;">
                        <div style="font-size: 9px; color: #94a3b8; text-transform: uppercase;">Departemen</div>
                        <div style="font-size: 12px; font-weight: 600; color: #475569;"><?php echo htmlspecialchars($slip['department']); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Salary Grid -->
            <div class="salary-grid">
                <!-- Earnings -->
                <div class="salary-section">
                    <div class="section-title">Pendapatan</div>
                    <div class="salary-row">
                        <span class="salary-label">Gaji Pokok</span>
                        <span class="salary-value"><?php echo number_format($slip['base_salary'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Lembur (<?php echo $slip['overtime_hours']; ?> jam)</span>
                        <span class="salary-value"><?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Service</span>
                        <span class="salary-value"><?php echo number_format($slip['incentive'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Tunjangan</span>
                        <span class="salary-value"><?php echo number_format($slip['allowance'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Uang Makan</span>
                        <span class="salary-value"><?php echo number_format($slip['uang_makan'] ?? 0, 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Bonus / Lainnya</span>
                        <span class="salary-value"><?php echo number_format($slip['bonus'] + $slip['other_income'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row salary-total">
                        <span class="salary-label">Total Pendapatan</span>
                        <span class="salary-value" style="color: #10b981;"><?php echo number_format($slip['total_earnings'], 0, ',', '.'); ?></span>
                    </div>
                </div>

                <!-- Deductions -->
                <div class="salary-section deductions">
                    <div class="section-title" style="color: #dc2626; border-color: #fecaca;">Potongan</div>
                    <div class="salary-row">
                        <span class="salary-label">Kasbon / Pinjaman</span>
                        <span class="salary-value"><?php echo number_format($slip['deduction_loan'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Absensi / Alpha</span>
                        <span class="salary-value"><?php echo number_format($slip['deduction_absence'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">BPJS</span>
                        <span class="salary-value"><?php echo number_format($slip['deduction_bpjs'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Pajak</span>
                        <span class="salary-value"><?php echo number_format($slip['deduction_tax'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row">
                        <span class="salary-label">Potongan Lain</span>
                        <span class="salary-value"><?php echo number_format($slip['deduction_other'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="salary-row salary-total">
                        <span class="salary-label">Total Potongan</span>
                        <span class="salary-value"><?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Net Salary -->
            <div class="net-salary-box">
                <div class="net-salary-label">Total Gaji Bersih (Take Home Pay)</div>
                <div class="net-salary-amount">Rp <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></div>
                <div class="net-salary-words">Terbilang: <?php echo terbilang($slip['net_salary']); ?> Rupiah</div>
            </div>

            <!-- Bank Transfer Info -->
            <?php if (!empty($slip['bank_name']) || !empty($slip['bank_account'])): ?>
                <div class="bank-info">
                    <div class="bank-info-title">💳 Informasi Transfer Pembayaran</div>
                    <div class="bank-grid">
                        <div>
                            <div class="bank-item-label">Nama Penerima</div>
                            <div class="bank-item-value"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                        </div>
                        <div>
                            <div class="bank-item-label">Bank</div>
                            <div class="bank-item-value"><?php echo htmlspecialchars($slip['bank_name'] ?: '-'); ?></div>
                        </div>
                        <div>
                            <div class="bank-item-label">Nomor Rekening</div>
                            <div class="bank-item-value"><?php echo htmlspecialchars($slip['bank_account'] ?: '-'); ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Signatures -->
            <div class="signatures">
                <div class="signature-box">
                    <div class="signature-title">Dibuat Oleh</div>
                    <div class="signature-line">Admin Keuangan</div>
                </div>
                <div class="signature-box">
                    <div class="signature-title">Disetujui Oleh</div>
                    <div class="signature-line">Manager / Owner</div>
                </div>
                <div class="signature-box">
                    <div class="signature-title">Jepara, <?php echo date('d M Y'); ?></div>
                    <div class="signature-line"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="slip-footer">
            Dokumen ini dicetak secara otomatis oleh sistem. Slip gaji ini sah tanpa tanda tangan basah.
            <br>
            <?php echo htmlspecialchars($businessName); ?> &bull; <?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>

    <script>
        function savePDF() {
            // Hide buttons before printing
            document.querySelector('.btn-group').style.display = 'none';

            // Use browser print to PDF functionality
            window.print();

            // Show buttons again after a delay
            setTimeout(function() {
                document.querySelector('.btn-group').style.display = 'flex';
            }, 1000);
        }
    </script>

</body>

</html>