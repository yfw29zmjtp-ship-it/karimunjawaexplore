<?php
// modules/payroll/print-submission.php - OWNER PAYROLL SUBMISSION WITH BANK DETAILS
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$db = Database::getInstance();
$period_id = (int)($_GET['period_id'] ?? 0);

// Shareable public link: token = sha256(period_id + DB_NAME + DB_HOST).
// Owner bisa buka link tanpa login asalkan token cocok.
$shareSalt = (defined('DB_HOST') ? DB_HOST : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|payroll-submission-v1';
$expectedToken = substr(hash('sha256', $period_id . '|' . $shareSalt), 0, 32);
$providedToken = (string)($_GET['token'] ?? '');
$isShareView = ($providedToken !== '' && hash_equals($expectedToken, $providedToken));

if (!$isShareView) {
    $auth = new Auth();
    $auth->requireLogin();
}

$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE id = ?", [$period_id]);
if (!$period) die("Period not found");

$slips = $db->fetchAll("SELECT ps.*, pe.bank_name, pe.bank_account,
                               COALESCE(pe.base_salary, ps.base_salary, 0) AS base_salary
                        FROM payroll_slips ps 
                        LEFT JOIN payroll_employees pe ON ps.employee_id = pe.id 
                        WHERE ps.period_id = ? ORDER BY ps.employee_name ASC", [$period_id]);

// Re-hitung tampilan agar SELALU konsisten dgn Process Salary
// (actual_base, OT amount, extra_amount, total_earnings, deductions, net)
foreach ($slips as $i => $s) {
    $base   = (float)$s['base_salary'];
    $wh     = (float)$s['work_hours'];
    $oh     = (float)$s['overtime_hours'];
    $extraLocked = !empty($s['extra_locked']);
    $exH    = (float)($s['extra_hours'] ?? 0);
    // Kalau Extra belum di-override manual & nilai stored masih 0,
    // hitung otomatis dari absensi (hari kerja >26).
    if (!$extraLocked) {
        try {
            $att = payrollAttendanceHours($db, (int)$s['employee_id'], (int)$period['period_month'], (int)$period['period_year']);
            $exH = (float)($att['extra_hours'] ?? 0);
        } catch (\Throwable $e) {
            // fallback: keep stored value
        }
    }
    $hourly = $base > 0 ? $base / 200 : 0;
    $actualBase  = ($wh >= 200) ? $base : round($wh * $hourly, 2);
    $otAmount    = round($oh * $hourly, 2);
    $extraAmount = round($exH * $hourly, 2);
    $loan    = (float)($s['deduction_loan'] ?? 0);
    $absence = (float)($s['deduction_absence'] ?? 0);
    $tax     = (float)($s['deduction_tax'] ?? 0);
    $bpjs    = (float)($s['deduction_bpjs'] ?? 0);
    $dedOth  = (float)($s['deduction_other'] ?? 0);
    $totalDed = $loan + $absence + $tax + $bpjs + $dedOth;
    $totalEarn = $actualBase + $otAmount + $extraAmount
        + (float)($s['incentive'] ?? 0) + (float)($s['allowance'] ?? 0)
        + (float)($s['bonus'] ?? 0) + (float)($s['other_income'] ?? 0);
    $slips[$i]['hourly_rate']      = $hourly;
    $slips[$i]['actual_base']      = $actualBase;
    $slips[$i]['overtime_amount']  = $otAmount;
    $slips[$i]['extra_hours']      = $exH;
    $slips[$i]['extra_amount']     = $extraAmount;
    $slips[$i]['ot_total_rp']      = $otAmount + $extraAmount;
    $slips[$i]['total_deductions'] = $totalDed;
    $slips[$i]['total_earnings']   = $totalEarn;
    $slips[$i]['net_salary']       = $totalEarn - $totalDed;
}

// Calculate totals
$totalBase       = array_sum(array_column($slips, 'base_salary'));
$totalActualBase = array_sum(array_column($slips, 'actual_base'));
$totalOtRp       = array_sum(array_column($slips, 'ot_total_rp'));
$totalOvertime   = array_sum(array_column($slips, 'overtime_amount'));
$totalExtra      = array_sum(array_column($slips, 'extra_amount'));
$totalIncentive  = array_sum(array_column($slips, 'incentive'));
$totalAllowance  = array_sum(array_column($slips, 'allowance'));
$totalBonus      = array_sum(array_column($slips, 'bonus'));
$totalOther      = array_sum(array_column($slips, 'other_income'));
$totalDeductions = array_sum(array_column($slips, 'total_deductions'));
$totalNet        = array_sum(array_column($slips, 'net_salary'));
$totalGross      = array_sum(array_column($slips, 'total_earnings'));

// Build shareable URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$shareUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?')
    . '?period_id=' . $period_id . '&token=' . $expectedToken;

$companyName = BUSINESS_NAME;
$monthNames = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];
$periodLabel = $monthNames[$period['period_month']] . ' ' . $period['period_year'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Payroll Submission - <?php echo $periodLabel; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 10pt;
            color: #1a1a2e;
            background: #f8fafc;
            padding: 20px;
        }

        .document {
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* Header */
        .doc-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 16px 24px;
            text-align: center;
        }

        .doc-header h1 {
            font-size: 12pt;
            font-weight: 700;
            letter-spacing: 1px;
            margin: 0 0 4px;
            text-transform: uppercase;
        }

        .doc-header h2 {
            font-size: 14pt;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .doc-header .period {
            font-size: 10pt;
            opacity: 0.9;
        }

        /* Summary Cards */
        .summary-section {
            padding: 16px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-title {
            font-size: 8pt;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .summary-card {
            background: #fff;
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #e2e8f0;
        }

        .summary-card label {
            display: block;
            font-size: 7pt;
            color: #64748b;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .summary-card .value {
            font-size: 11pt;
            font-weight: 700;
            color: #1a1a2e;
        }

        .summary-card.highlight {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            border: none;
        }

        .summary-card.highlight label {
            color: rgba(255, 255, 255, 0.8);
        }

        .summary-card.highlight .value {
            color: #fff;
        }

        /* Employee Table */
        .table-section {
            padding: 16px 24px;
        }

        .table-title {
            font-size: 8pt;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }

        th {
            background: #f1f5f9;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        tr:hover td {
            background: #f8fafc;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .emp-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 8pt;
        }

        .emp-position {
            font-size: 7pt;
            color: #64748b;
        }

        .amount {
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 8pt;
        }

        .amount.positive {
            color: #10b981;
        }

        .amount.negative {
            color: #ef4444;
        }

        .amount.net {
            font-weight: 700;
            color: #667eea;
        }

        tfoot td {
            background: #1a1a2e;
            color: #fff;
            font-weight: 700;
            padding: 8px;
        }

        tfoot .amount {
            color: #fff;
        }

        /* Bank Transfer Section */
        .bank-section {
            padding: 16px 24px;
            background: #fffbeb;
            border-top: 2px solid #f59e0b;
        }

        .bank-section .bank-title {
            font-size: 9pt;
            font-weight: 700;
            color: #b45309;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bank-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }

        .bank-table th {
            background: #fef3c7;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            color: #92400e;
            border-bottom: 1px solid #fcd34d;
            font-size: 7pt;
        }

        .bank-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #fef3c7;
        }

        .bank-table .bank-info {
            font-weight: 600;
            color: #92400e;
        }

        .bank-table .acc-number {
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 9pt;
            font-weight: 600;
            color: #1a1a2e;
            letter-spacing: 0.5px;
        }

        .copy-btn {
            background: #f59e0b;
            color: #fff;
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 7pt;
            cursor: pointer;
            margin-left: 6px;
        }

        .copy-btn:hover {
            background: #d97706;
        }

        @media print {
            .copy-btn {
                display: none;
            }
        }

        /* Total Box */
        .total-box {
            margin: 16px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
        }

        .total-box .label {
            font-size: 10pt;
            font-weight: 500;
        }

        .total-box .amount {
            font-size: 16pt;
            font-weight: 700;
            color: #fff;
        }

        .total-box .words {
            font-size: 8pt;
            opacity: 0.8;
            margin-top: 3px;
        }

        /* Signatures */
        .signature-section {
            padding: 20px 24px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            page-break-inside: avoid;
        }

        .sig-block {
            text-align: center;
        }

        .sig-title {
            font-size: 8pt;
            color: #64748b;
            margin-bottom: 50px;
        }

        .sig-line {
            border-bottom: 1px solid #1a1a2e;
            margin-bottom: 5px;
        }

        .sig-name {
            font-size: 8pt;
            font-weight: 600;
            color: #1a1a2e;
        }

        /* Print Styles */
        @media print {
            body {
                background: #fff;
                padding: 0;
                font-size: 9pt;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .document {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }

            .doc-header,
            .summary-card.highlight,
            .total-box,
            tfoot td,
            .bank-section {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @page {
                size: A4 landscape;
                margin: 8mm;
            }

            .bank-section {
                page-break-inside: avoid;
            }
        }

        /* Button for screen */
        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.4);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .print-btn:hover {
            transform: translateY(-2px);
        }

        @media print {
            .print-btn {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="document">

        <!-- Header -->
        <div class="doc-header">
            <h1><?php echo strtoupper($companyName); ?></h1>
            <h2>Payroll Submission Request</h2>
            <div class="period">Period: <?php echo $periodLabel; ?></div>
        </div>

        <!-- Summary Section -->
        <div class="summary-section">
            <div class="summary-title">Payroll Summary</div>
            <div class="summary-grid">
                <div class="summary-card">
                    <label>Total Employees</label>
                    <div class="value"><?php echo count($slips); ?></div>
                </div>
                <div class="summary-card">
                    <label>Total Gross Salary</label>
                    <div class="value">Rp <?php echo number_format($totalGross, 0, ',', '.'); ?></div>
                </div>
                <div class="summary-card">
                    <label>Total Deductions</label>
                    <div class="value" style="color: #ef4444;">Rp <?php echo number_format($totalDeductions, 0, ',', '.'); ?></div>
                </div>
                <div class="summary-card highlight">
                    <label>Net Amount Required</label>
                    <div class="value">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>

        <!-- Employee Detail Table -->
        <div class="table-section">
            <div class="table-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Employee Salary Details (sama dengan Process Salary)</span>
                <span style="font-size:7pt;color:#94a3b8;text-transform:none;font-weight:500;">Hourly rate = Base ÷ 200 · OT/Extra dibayar pakai rate jam yg sama</span>
            </div>
            <div style="overflow-x:auto;">
                <table style="font-size:7.5pt;">
                    <thead>
                        <tr>
                            <th style="width: 24px;">#</th>
                            <th>Employee</th>
                            <th class="text-right">Base</th>
                            <th class="text-center" title="Jam absensi">Hours</th>
                            <th class="text-right" title="Base proporsional sesuai jam (full bila ≥200)">Actual</th>
                            <th class="text-center" title="OT approved (jam penuh)">OT</th>
                            <th class="text-center" style="color:#b91c1c;" title="Hari kerja ke-27+">Extra</th>
                            <th class="text-right" title="(OT + Extra) × rate jam">OT Rp</th>
                            <th class="text-right">Service</th>
                            <th class="text-right">Allowc</th>
                            <th class="text-right">Bonus</th>
                            <th class="text-right" style="color:#ef4444;">Deduct</th>
                            <th class="text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1;
                        foreach ($slips as $slip):
                            $bonusAll = (float)($slip['bonus'] ?? 0) + (float)($slip['other_income'] ?? 0);
                            $exH = (float)($slip['extra_hours'] ?? 0);
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $no++; ?></td>
                                <td>
                                    <div class="emp-name"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                                    <div class="emp-position"><?php echo htmlspecialchars($slip['position']); ?></div>
                                </td>
                                <td class="text-right"><span class="amount"><?php echo number_format($slip['base_salary'], 0, ',', '.'); ?></span></td>
                                <td class="text-center"><span class="amount"><?php echo number_format((float)$slip['work_hours'], 1, ',', '.'); ?></span></td>
                                <td class="text-right"><span class="amount"><?php echo number_format($slip['actual_base'], 0, ',', '.'); ?></span></td>
                                <td class="text-center"><span class="amount"><?php echo (float)$slip['overtime_hours'] > 0 ? number_format((float)$slip['overtime_hours'], 0) . 'j' : '—'; ?></span></td>
                                <td class="text-center"><span class="amount" style="color:<?php echo $exH > 0 ? '#b91c1c' : '#94a3b8'; ?>;font-weight:<?php echo $exH > 0 ? '700' : '400'; ?>;"><?php echo $exH > 0 ? '+' . number_format($exH, 0) . 'j' : '—'; ?></span></td>
                                <td class="text-right"><span class="amount positive" title="OT Rp <?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?> + Extra Rp <?php echo number_format($slip['extra_amount'], 0, ',', '.'); ?>"><?php echo number_format($slip['ot_total_rp'], 0, ',', '.'); ?></span></td>
                                <td class="text-right"><span class="amount"><?php echo number_format($slip['incentive'], 0, ',', '.'); ?></span></td>
                                <td class="text-right"><span class="amount"><?php echo number_format($slip['allowance'], 0, ',', '.'); ?></span></td>
                                <td class="text-right"><span class="amount"><?php echo number_format($bonusAll, 0, ',', '.'); ?></span></td>
                                <td class="text-right"><span class="amount negative"><?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></span></td>
                                <td class="text-right"><span class="amount net"><?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right">TOTAL</td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalActualBase, 0, ',', '.'); ?></span></td>
                            <td colspan="2"></td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalOtRp, 0, ',', '.'); ?></span></td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalIncentive, 0, ',', '.'); ?></span></td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalAllowance, 0, ',', '.'); ?></span></td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalBonus + $totalOther, 0, ',', '.'); ?></span></td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalDeductions, 0, ',', '.'); ?></span></td>
                            <td class="text-right"><span class="amount"><?php echo number_format($totalNet, 0, ',', '.'); ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Bank Transfer Details -->
        <div class="bank-section">
            <div class="bank-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                    <line x1="1" y1="10" x2="23" y2="10"></line>
                </svg>
                BANK TRANSFER DETAILS (For Owner / Transfer Person)
            </div>
            <table class="bank-table">
                <thead>
                    <tr>
                        <th style="width: 30px;">#</th>
                        <th>Employee Name</th>
                        <th>Bank Name</th>
                        <th>Account Number</th>
                        <th class="text-right">Amount to Transfer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1;
                    foreach ($slips as $slip): ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td class="bank-info"><?php echo htmlspecialchars($slip['employee_name']); ?></td>
                            <td><?php echo htmlspecialchars($slip['bank_name'] ?? '-'); ?></td>
                            <td>
                                <span class="acc-number" id="acc-<?php echo $slip['id']; ?>"><?php echo htmlspecialchars($slip['bank_account'] ?? '-'); ?></span>
                                <?php if (!empty($slip['bank_account'])): ?>
                                    <button class="copy-btn" onclick="copyToClipboard('acc-<?php echo $slip['id']; ?>')">Copy</button>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><span class="amount net">Rp <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Grand Total Box -->
        <div class="total-box">
            <div>
                <div class="label">Total Amount Required for Payroll Disbursement:</div>
                <div class="words">(Bank Transfer)</div>
            </div>
            <div style="text-align: right;">
                <div class="amount">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="sig-block">
                <div class="sig-title">Prepared by</div>
                <div class="sig-line"></div>
                <div class="sig-name">Admin / HR</div>
            </div>
            <div class="sig-block">
                <div class="sig-title">Reviewed by</div>
                <div class="sig-line"></div>
                <div class="sig-name">Finance Manager</div>
            </div>
            <div class="sig-block">
                <div class="sig-title">Approved by</div>
                <div class="sig-line"></div>
                <div class="sig-name">Owner</div>
            </div>
        </div>

    </div>

    <button class="print-btn" onclick="window.print()" style="right:20px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 6 2 18 2 18 9"></polyline>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
            <rect x="6" y="14" width="12" height="8"></rect>
        </svg>
        Print Document
    </button>

    <?php if (!$isShareView): ?>
        <button class="print-btn" id="shareBtn" onclick="copyShareLink()" style="right:200px;background:linear-gradient(135deg,#10b981 0%,#059669 100%);box-shadow:0 4px 16px rgba(16,185,129,0.4);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="18" cy="5" r="3"></circle>
                <circle cx="6" cy="12" r="3"></circle>
                <circle cx="18" cy="19" r="3"></circle>
                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
            </svg>
            Share Link Owner
        </button>
    <?php else: ?>
        <div style="position:fixed;top:10px;left:10px;background:#10b981;color:#fff;padding:6px 12px;border-radius:20px;font-size:11px;font-weight:600;box-shadow:0 2px 8px rgba(0,0,0,0.15);">🔗 Public Share View (read-only)</div>
    <?php endif; ?>

    <script>
        const SHARE_URL = <?php echo json_encode($shareUrl); ?>;

        function copyShareLink() {
            navigator.clipboard.writeText(SHARE_URL).then(() => {
                const btn = document.getElementById('shareBtn');
                const orig = btn.innerHTML;
                btn.innerHTML = '✅ Link disalin!';
                btn.style.background = '#22c55e';
                setTimeout(() => {
                    btn.innerHTML = orig;
                    btn.style.background = 'linear-gradient(135deg,#10b981 0%,#059669 100%)';
                }, 1800);
            }).catch(() => {
                prompt('Salin link ini:', SHARE_URL);
            });
        }

        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent.trim();
            navigator.clipboard.writeText(text).then(() => {
                const btn = element.nextElementSibling;
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.background = '#22c55e';
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '#f59e0b';
                }, 1500);
            });
        }
    </script>

</body>

</html>