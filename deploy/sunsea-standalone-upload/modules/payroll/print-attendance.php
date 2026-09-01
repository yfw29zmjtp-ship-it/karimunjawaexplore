<?php
// modules/payroll/print-attendance.php - PRINT LAPORAN ABSENSI BULANAN PER STAFF
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

$empId = (int)($_GET['emp_id'] ?? 0);
$m = (int)($_GET['m'] ?? date('n'));
$y = (int)($_GET['y'] ?? date('Y'));
$monthStr = sprintf('%04d-%02d', $y, $m);

$emp = $db->fetchOne("SELECT full_name, position, monthly_target_hours FROM payroll_employees WHERE id = ?", [$empId]);
if (!$emp) {
    die("Data karyawan tidak ditemukan.");
}

$attendance = $db->fetchAll(
    "SELECT attendance_date, check_in_time, check_out_time, scan_3, scan_4,
            work_hours, shift_1_hours, shift_2_hours, status, notes
     FROM payroll_attendance
     WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
     ORDER BY attendance_date ASC",
    [$empId, $monthStr]
);

$byDate = [];
foreach ($attendance as $a) {
    $byDate[$a['attendance_date']] = $a;
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);

$totalDays = 0;
$totalHours = 0.0;
$lateCount = 0;
$absentCount = 0;

$rows = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%04d-%02d-%02d', $y, $m, $d);
    $a = $byDate[$dateStr] ?? null;
    $effectiveStatus = $a['status'] ?? null;
    if ($a) {
        $totalDays++;
        $totalHours += (float)($a['work_hours'] ?? 0);
        $effectiveStatus = payrollDetectLateArrival($effectiveStatus, $a['check_in_time'] ?? null);
        if ($effectiveStatus === 'late') $lateCount++;
        if ($effectiveStatus === 'absent') $absentCount++;
    }
    $rows[] = ['date' => $dateStr, 'day' => $d, 'attendance' => $a, 'effective_status' => $effectiveStatus];
}

$statusLabels = [
    'present' => 'Hadir',
    'late' => 'Terlambat',
    'absent' => 'Absen',
    'leave' => 'Cuti',
    'holiday' => 'Libur',
    'half_day' => '½ Hari',
];

$monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$periodLabel = $monthNames[$m] . ' ' . $y;
$targetHours = (int)($emp['monthly_target_hours'] ?? 200);
$progressPct = $targetHours > 0 ? min(round(($totalHours / $targetHours) * 100), 100) : 0;

$businessName = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Perusahaan';

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
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Absensi <?php echo htmlspecialchars($emp['full_name']); ?> - <?php echo $periodLabel; ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',-apple-system,sans-serif;font-size:10pt;color:#1e293b;background:#f1f5f9;padding:20px}
    .sheet{width:100%;max-width:850px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);overflow:hidden}
    .sheet-header{background:linear-gradient(135deg,#1e1b4b,#4338ca);color:#fff;padding:22px 28px;display:flex;align-items:center;gap:18px}
    .sheet-logo{width:50px;height:50px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .sheet-logo img{max-width:40px;max-height:40px;object-fit:contain}
    .sheet-logo-text{font-size:20px;font-weight:700}
    .sheet-hinfo{flex:1}
    .sheet-hinfo h1{font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}
    .sheet-hinfo p{font-size:11px;opacity:.85}
    .sheet-period{text-align:right}
    .sheet-period small{font-size:9px;opacity:.7;text-transform:uppercase;letter-spacing:1px}
    .sheet-period div{font-size:14px;font-weight:700;margin-top:2px}
    .sheet-body{padding:22px 28px}
    .emp-card{background:linear-gradient(135deg,#f8fafc,#e2e8f0);border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
    .emp-name{font-size:14px;font-weight:700;color:#1e293b;margin-bottom:2px}
    .emp-detail{font-size:10px;color:#64748b}
    .summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
    .summary-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;text-align:center}
    .summary-card .val{font-size:18px;font-weight:800;color:#1e293b}
    .summary-card .lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
    .progress-wrap{background:#f1f5f9;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:10px;color:#475569}
    .progress-bar-bg{background:#e2e8f0;border-radius:6px;height:8px;margin-top:6px;overflow:hidden}
    .progress-bar-fill{background:linear-gradient(90deg,#3b82f6,#10b981);height:100%;border-radius:6px}
    .att-table{width:100%;border-collapse:collapse;font-size:9pt}
    .att-table th{text-align:left;padding:7px 8px;font-size:8.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;border-bottom:2px solid #e2e8f0;background:#f8fafc}
    .att-table td{padding:6px 8px;border-bottom:1px solid #f1f5f9}
    .att-table tr.weekend td{background:#f8fafc;color:#94a3b8}
    .att-table tr.st-late td{color:#b45309}
    .att-table tr.st-absent td{color:#b91c1c}
    .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:8pt;font-weight:700}
    .badge-present{background:#dcfce7;color:#166534}
    .badge-late{background:#fef3c7;color:#92400e}
    .badge-absent{background:#fee2e2;color:#991b1b}
    .badge-leave{background:#dbeafe;color:#1d4ed8}
    .badge-holiday{background:#f1f5f9;color:#64748b}
    .badge-half_day{background:#ede9fe;color:#5b21b6}
    .sheet-footer{border-top:2px solid #e2e8f0;padding:14px 28px;display:flex;justify-content:space-between;align-items:center}
    .sheet-footer small{font-size:8px;color:#94a3b8}
    .sheet-sign{text-align:center}
    .sheet-sign .line{width:120px;border-bottom:1px solid #cbd5e1;margin:30px auto 4px}
    .sheet-sign small{font-size:8px;color:#94a3b8}
    .no-print{margin:20px auto;max-width:850px;display:flex;gap:8px;justify-content:center}
    .no-print button,.no-print a{padding:10px 20px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;border:none;display:flex;align-items:center;gap:6px}
    .btn-print{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff}
    .btn-back{background:#e2e8f0;color:#475569}
    @media print{
        .no-print{display:none!important}
        body{background:#fff;padding:0}
        .sheet{box-shadow:none;border-radius:0}
        .att-table{page-break-inside:auto}
        .att-table tr{page-break-inside:avoid}
    }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()" class="btn-print">🖨️ Print</button>
    <a href="javascript:history.back()" class="btn-back">← Kembali</a>
</div>

<div class="sheet">
    <div class="sheet-header">
        <div class="sheet-logo">
            <?php if ($logoUrl): ?>
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo">
            <?php else: ?>
                <span class="sheet-logo-text"><?php echo strtoupper(substr($businessName, 0, 1)); ?></span>
            <?php endif; ?>
        </div>
        <div class="sheet-hinfo">
            <h1>Laporan Absensi Bulanan</h1>
            <p><?php echo htmlspecialchars($businessName); ?></p>
        </div>
        <div class="sheet-period">
            <small>Periode</small>
            <div><?php echo $periodLabel; ?></div>
        </div>
    </div>

    <div class="sheet-body">
        <div class="emp-card">
            <div>
                <div class="emp-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                <div class="emp-detail"><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></div>
            </div>
        </div>

        <div class="summary">
            <div class="summary-card">
                <div class="val"><?php echo $totalDays; ?></div>
                <div class="lbl">Hari Hadir</div>
            </div>
            <div class="summary-card">
                <div class="val"><?php echo round($totalHours, 1); ?></div>
                <div class="lbl">Total Jam</div>
            </div>
            <div class="summary-card">
                <div class="val"><?php echo $lateCount; ?></div>
                <div class="lbl">Terlambat</div>
            </div>
            <div class="summary-card">
                <div class="val"><?php echo $absentCount; ?></div>
                <div class="lbl">Tidak Hadir</div>
            </div>
        </div>

        <div class="progress-wrap">
            Progress Jam Kerja: <strong><?php echo round($totalHours, 1); ?></strong> / <?php echo $targetHours; ?> jam (<?php echo $progressPct; ?>%)
            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?php echo $progressPct; ?>%"></div></div>
        </div>

        <table class="att-table">
            <thead>
                <tr>
                    <th>Tgl</th>
                    <th>Scan 1</th>
                    <th>Scan 2</th>
                    <th>Scan 3</th>
                    <th>Scan 4</th>
                    <th>S1</th>
                    <th>S2</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row):
                    $a = $row['attendance'];
                    $dayOfWeek = (int)date('N', strtotime($row['date']));
                    $isWeekend = $dayOfWeek >= 6;
                    $dayName = date('D', strtotime($row['date']));
                    $status = $row['effective_status'] ?? ($isWeekend ? 'holiday' : '');
                    $rowClass = $isWeekend ? 'weekend' : ($status === 'late' ? 'st-late' : ($status === 'absent' ? 'st-absent' : ''));
                    $ci = $a && $a['check_in_time'] ? substr($a['check_in_time'], 0, 5) : '--:--';
                    $co = $a && $a['check_out_time'] ? substr($a['check_out_time'], 0, 5) : '--:--';
                    $s3 = $a && $a['scan_3'] ? substr($a['scan_3'], 0, 5) : '--:--';
                    $s4 = $a && $a['scan_4'] ? substr($a['scan_4'], 0, 5) : '--:--';
                    $sh1 = $a && $a['shift_1_hours'] ? number_format((float)$a['shift_1_hours'], 1) : '0.0';
                    $sh2 = $a && $a['shift_2_hours'] ? number_format((float)$a['shift_2_hours'], 1) : '0.0';
                    $tot = $a && $a['work_hours'] ? number_format((float)$a['work_hours'], 1) : '0.0';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td><?php echo $row['day'] . ' ' . $dayName; ?></td>
                    <td><?php echo $ci; ?></td>
                    <td><?php echo $co; ?></td>
                    <td><?php echo $s3; ?></td>
                    <td><?php echo $s4; ?></td>
                    <td><?php echo $sh1; ?></td>
                    <td><?php echo $sh2; ?></td>
                    <td><strong><?php echo $tot; ?></strong></td>
                    <td><?php if ($status): ?><span class="badge badge-<?php echo $status; ?>"><?php echo $statusLabels[$status] ?? $status; ?></span><?php else: ?>—<?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="sheet-footer">
        <small>Dicetak pada <?php echo date('d M Y H:i'); ?></small>
        <div class="sheet-sign">
            <div class="line"></div>
            <small>Tanda Tangan</small>
        </div>
    </div>
</div>

</body>
</html>
