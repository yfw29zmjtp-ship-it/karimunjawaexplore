<?php
// modules/payroll/print-weekly-slip.php - SLIP GAJI MINGGUAN
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);

$rec = $db->fetchOne("SELECT w.*, e.employee_code, e.bank_name, e.bank_account, e.phone
    FROM payroll_weekly w
    LEFT JOIN payroll_employees e ON w.employee_id = e.id
    WHERE w.id = ?", [$id]);

if (!$rec) { die("Data slip gaji mingguan tidak ditemukan."); }

$monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$periodLabel = $monthNames[$rec['period_month']] . ' ' . $rec['period_year'];
$businessName = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Hotel';

// Logo
$logoUrl = '';
$businessId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : '';
foreach (['invoice_logo_' . $businessId, 'invoice_logo', 'company_logo'] as $key) {
    $logo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    if ($logo && !empty($logo['setting_value'])) {
        $val = $logo['setting_value'];
        $logoUrl = (strpos($val, 'http') === 0) ? $val : BASE_URL . '/' . ltrim($val, '/');
        break;
    }
}

// WhatsApp
$waNumber = '';
if (!empty($rec['phone'])) {
    $phone = preg_replace('/[^0-9]/', '', $rec['phone']);
    if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);
    elseif (substr($phone, 0, 2) !== '62') $phone = '62' . $phone;
    $waNumber = $phone;
}
$waMsg = urlencode("Halo {$rec['employee_name']},\n\nBerikut slip gaji mingguan Anda untuk {$periodLabel}:\n\nMinggu 1: Rp " . number_format($rec['week_1'],0,',','.') . "\nMinggu 2: Rp " . number_format($rec['week_2'],0,',','.') . "\nMinggu 3: Rp " . number_format($rec['week_3'],0,',','.') . "\nMinggu 4: Rp " . number_format($rec['week_4'],0,',','.') . "\n\nTotal: Rp " . number_format($rec['total_salary'],0,',','.') . "\n\nTerima kasih.");
$waLink = $waNumber ? "https://wa.me/{$waNumber}?text={$waMsg}" : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Slip Gaji Mingguan - <?php echo htmlspecialchars($rec['employee_name']); ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',-apple-system,sans-serif;font-size:10pt;color:#1e293b;background:#f1f5f9;padding:20px}
        .slip{width:100%;max-width:700px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);overflow:hidden}
        .slip-header{background:linear-gradient(135deg,#1e1b4b,#4338ca);color:#fff;padding:25px 30px;display:flex;align-items:center;gap:20px}
        .slip-logo{width:55px;height:55px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .slip-logo img{max-width:45px;max-height:45px;object-fit:contain}
        .slip-logo-text{font-size:22px;font-weight:700}
        .slip-hinfo{flex:1}
        .slip-hinfo h1{font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}
        .slip-hinfo p{font-size:11px;opacity:.85}
        .slip-period{text-align:right}
        .slip-period small{font-size:9px;opacity:.7;text-transform:uppercase;letter-spacing:1px}
        .slip-period div{font-size:15px;font-weight:700;margin-top:2px}
        .slip-body{padding:25px 30px}
        .emp-card{background:linear-gradient(135deg,#f8fafc,#e2e8f0);border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center}
        .emp-name{font-size:15px;font-weight:700;color:#1e293b;margin-bottom:3px}
        .emp-detail{font-size:10px;color:#64748b}
        .emp-code{font-size:9px;color:#94a3b8;font-family:monospace}
        .sec-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748b;margin-bottom:8px;padding-bottom:5px;border-bottom:2px solid #e2e8f0}
        .week-table{width:100%;border-collapse:collapse;margin-bottom:20px}
        .week-table th{text-align:left;padding:10px 12px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0}
        .week-table td{padding:10px 12px;font-size:11px;border-bottom:1px solid #f1f5f9}
        .week-table .amount{text-align:right;font-family:'SF Mono',monospace;font-weight:600}
        .week-table .total-row{background:linear-gradient(135deg,#f0fdf4,#dcfce7);font-weight:800}
        .week-table .total-row td{border-bottom:none;font-size:13px;color:#059669}
        .bank-info{background:#f8fafc;border-radius:8px;padding:14px 18px;margin-bottom:15px;display:flex;gap:30px}
        .bank-info div{font-size:10px;color:#64748b}
        .bank-info strong{color:#1e293b;font-size:11px}
        .slip-footer{border-top:2px solid #e2e8f0;padding:15px 30px;display:flex;justify-content:space-between;align-items:center}
        .slip-footer small{font-size:8px;color:#94a3b8}
        .slip-sign{text-align:center}
        .slip-sign .line{width:120px;border-bottom:1px solid #cbd5e1;margin:30px auto 4px}
        .slip-sign small{font-size:8px;color:#94a3b8}
        .no-print{margin:20px auto;max-width:700px;display:flex;gap:8px;justify-content:center}
        .no-print a,.no-print button{padding:10px 20px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;border:none;display:flex;align-items:center;gap:6px}
        .btn-print{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff}
        .btn-wa{background:#25d366;color:#fff}
        .btn-back{background:#e2e8f0;color:#475569}
        @media print{.no-print{display:none!important}body{background:#fff;padding:0}.slip{box-shadow:none;border-radius:0}}
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()" class="btn-print">🖨️ Print</button>
    <?php if ($waLink): ?>
    <a href="<?php echo $waLink; ?>" target="_blank" class="btn-wa">📱 WhatsApp</a>
    <?php endif; ?>
    <a href="weekly-payroll.php?month=<?php echo $rec['period_month']; ?>&year=<?php echo $rec['period_year']; ?>" class="btn-back">← Kembali</a>
</div>

<div class="slip">
    <div class="slip-header">
        <div class="slip-logo">
            <?php if ($logoUrl): ?>
            <img src="<?php echo $logoUrl; ?>" alt="Logo">
            <?php else: ?>
            <span class="slip-logo-text">🏨</span>
            <?php endif; ?>
        </div>
        <div class="slip-hinfo">
            <h1><?php echo htmlspecialchars($businessName); ?></h1>
            <p>Slip Gaji Mingguan</p>
        </div>
        <div class="slip-period">
            <small>Periode</small>
            <div><?php echo $periodLabel; ?></div>
        </div>
    </div>

    <div class="slip-body">
        <!-- Employee Card -->
        <div class="emp-card">
            <div>
                <div class="emp-name"><?php echo htmlspecialchars($rec['employee_name']); ?></div>
                <div class="emp-detail"><?php echo htmlspecialchars($rec['position'] ?? '-'); ?> — <?php echo htmlspecialchars($rec['department'] ?? '-'); ?></div>
                <div class="emp-code"><?php echo htmlspecialchars($rec['employee_code'] ?? ''); ?></div>
            </div>
            <div style="text-align:right">
                <div style="font-size:9px;color:#94a3b8;text-transform:uppercase">Status</div>
                <div style="font-size:12px;font-weight:700;color:<?php echo $rec['status']==='paid'?'#059669':'#f59e0b'; ?>">
                    <?php echo $rec['status'] === 'paid' ? '✅ LUNAS' : '⏳ DRAFT'; ?>
                </div>
            </div>
        </div>

        <!-- Weekly Breakdown -->
        <div class="sec-title">💰 Rincian Gaji Mingguan</div>
        <table class="week-table">
            <thead>
                <tr>
                    <th>Minggu</th>
                    <th class="amount">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Minggu 1</td>
                    <td class="amount">Rp <?php echo number_format($rec['week_1'], 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <td>Minggu 2</td>
                    <td class="amount">Rp <?php echo number_format($rec['week_2'], 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <td>Minggu 3</td>
                    <td class="amount">Rp <?php echo number_format($rec['week_3'], 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <td>Minggu 4</td>
                    <td class="amount">Rp <?php echo number_format($rec['week_4'], 0, ',', '.'); ?></td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL GAJI</td>
                    <td class="amount">Rp <?php echo number_format($rec['total_salary'], 0, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($rec['notes'])): ?>
        <div style="background:#fffbeb;border-radius:6px;padding:10px 14px;margin-bottom:15px;font-size:10px;color:#92400e">
            <strong>📝 Catatan:</strong> <?php echo htmlspecialchars($rec['notes']); ?>
        </div>
        <?php endif; ?>

        <!-- Bank Info -->
        <?php if (!empty($rec['bank_name']) || !empty($rec['bank_account'])): ?>
        <div class="sec-title">🏦 Informasi Bank</div>
        <div class="bank-info">
            <div>Bank<br><strong><?php echo htmlspecialchars($rec['bank_name'] ?? '-'); ?></strong></div>
            <div>No. Rekening<br><strong><?php echo htmlspecialchars($rec['bank_account'] ?? '-'); ?></strong></div>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div style="display:flex;justify-content:space-between;margin-top:30px">
            <div class="slip-sign">
                <small>Penerima</small>
                <div class="line"></div>
                <small><?php echo htmlspecialchars($rec['employee_name']); ?></small>
            </div>
            <div class="slip-sign">
                <small>Disetujui</small>
                <div class="line"></div>
                <small>Management</small>
            </div>
        </div>
    </div>

    <div class="slip-footer">
        <small>Dicetak: <?php echo date('d M Y, H:i'); ?> WIB</small>
        <small>Slip Gaji Mingguan — <?php echo htmlspecialchars($businessName); ?> © <?php echo date('Y'); ?></small>
    </div>
</div>

</body>
</html>
