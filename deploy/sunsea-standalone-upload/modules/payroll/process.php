<?php
// modules/payroll/process.php - MODERN 2027 DESIGN WITH WORK HOURS LOGIC
// VERSION: 2026-04-05-v9 (fix silent DB errors, robust sync, full net calc on create)
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Helper: Execute query that MUST succeed — throws on failure
// $db->query() silently swallows PDOException and returns false
function dbExec($db, $sql, $params = [])
{
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Process Salary';

// ═══ AJAX: Get Monthly Attendance Detail ═══
if (isset($_GET['ajax_attendance']) && isset($_GET['emp_id'])) {
    header('Content-Type: application/json');
    $empId = (int)$_GET['emp_id'];
    $m = (int)($_GET['m'] ?? date('n'));
    $y = (int)($_GET['y'] ?? date('Y'));
    $monthStr = sprintf('%04d-%02d', $y, $m);

    try {
        // Get employee info
        $emp = $db->fetchOne("SELECT full_name, position, monthly_target_hours FROM payroll_employees WHERE id = ?", [$empId]);

        // Get all attendance for this month
        $attendance = $db->fetchAll(
            "SELECT attendance_date, check_in_time, check_out_time, scan_3, scan_4, 
                    work_hours, shift_1_hours, shift_2_hours, status, notes,
                    check_in_distance_m, is_outside_radius
             FROM payroll_attendance 
             WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
             ORDER BY attendance_date ASC",
            [$empId, $monthStr]
        );

        // Calculate summary
        $totalDays = 0;
        $totalHours = 0;
        $lateCount = 0;
        $absentCount = 0;
        $presentCount = 0;

        foreach ($attendance as &$a) {
            $a['effective_status'] = payrollDetectLateArrival($a['status'], $a['check_in_time']);
        }
        unset($a);

        foreach ($attendance as $a) {
            $totalDays++;
            $totalHours += (float)($a['work_hours'] ?? 0);
            if ($a['effective_status'] === 'late') $lateCount++;
            if ($a['effective_status'] === 'absent') $absentCount++;
            if ($a['effective_status'] === 'present' || $a['effective_status'] === 'late') $presentCount++;
        }

        // Get days in month
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);

        // Build calendar data
        $calendarData = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf('%04d-%02d-%02d', $y, $m, $d);
            $dayOfWeek = date('N', strtotime($dateStr)); // 1=Monday, 7=Sunday
            $calendarData[$dateStr] = [
                'date' => $dateStr,
                'day' => $d,
                'day_name' => date('D', strtotime($dateStr)),
                'is_weekend' => ($dayOfWeek >= 6),
                'attendance' => null
            ];
        }

        // Merge attendance data
        foreach ($attendance as $a) {
            $calendarData[$a['attendance_date']]['attendance'] = $a;
        }

        echo json_encode([
            'success' => true,
            'employee' => $emp,
            'employee_id' => $empId,
            'month' => $m,
            'year' => $y,
            'month_name' => date('F Y', strtotime("$y-$m-01")),
            'summary' => [
                'total_days' => $presentCount,
                'total_hours' => round($totalHours, 1),
                'target_hours' => (int)($emp['monthly_target_hours'] ?? 200),
                'late_count' => $lateCount,
                'absent_count' => $absentCount,
                'days_in_month' => $daysInMonth
            ],
            'calendar' => array_values($calendarData)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Ensure all required columns exist
try {
    $pdo = $db->getConnection();
    $cols = [
        "ADD COLUMN IF NOT EXISTS work_hours DECIMAL(10,2) NOT NULL DEFAULT 200.00 AFTER position",
        "ADD COLUMN IF NOT EXISTS actual_base DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER work_hours",
        "ADD COLUMN IF NOT EXISTS is_paid TINYINT(1) NOT NULL DEFAULT 0",
        "ADD COLUMN IF NOT EXISTS hours_locked TINYINT(1) NOT NULL DEFAULT 0",
        "ADD COLUMN IF NOT EXISTS incentive DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS allowance DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS uang_makan DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS bonus DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS other_income DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS deduction_loan DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS deduction_absence DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS deduction_tax DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS deduction_bpjs DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS deduction_other DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS total_deductions DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS net_salary DECIMAL(15,2) DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS locked TINYINT(1) NOT NULL DEFAULT 0",
        "ADD COLUMN IF NOT EXISTS extra_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "ADD COLUMN IF NOT EXISTS extra_locked TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $c) {
        try {
            $pdo->exec("ALTER TABLE payroll_slips $c");
        } catch (\Throwable $e) {
        }
    }
} catch (\Throwable $e) {
    // Schema migration errors are non-fatal
}

$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');

// Periode SEBELUM bulan berjalan dibekukan: total gaji historis tidak boleh
// berubah oleh sync ulang. Bulan ini & masa depan tetap auto-sync.
$isCurrentOrFuture = ((int)$year > (int)date('Y'))
    || ((int)$year === (int)date('Y') && (int)$month >= (int)date('n'));
$isFrozen = !$isCurrentOrFuture;
$months = [
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

$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);

// ── Helper: Recalculate ALL work_hours in payroll_attendance from scan timestamps ──
// Fixes any records where work_hours was not stored correctly
function recalcAttendanceHours($db, $month, $year)
{
    $monthStr = sprintf('%04d-%02d', $year, $month);
    $rows = $db->fetchAll(
        "SELECT id, check_in_time, check_out_time, scan_3, scan_4, attendance_date
         FROM payroll_attendance 
         WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ?
         AND check_in_time IS NOT NULL",
        [$monthStr]
    );
    foreach ($rows as $r) {
        $sh1 = null;
        $sh2 = null;
        $scanDate = $r['attendance_date'];
        if (!empty($r['check_in_time']) && !empty($r['check_out_time'])) {
            $t1 = strtotime($scanDate . ' ' . $r['check_in_time']);
            $t2 = strtotime($scanDate . ' ' . $r['check_out_time']);
            if ($t2 > $t1) $sh1 = round(($t2 - $t1) / 3600, 2);
        }
        if (!empty($r['scan_3']) && !empty($r['scan_4'])) {
            $t3 = strtotime($scanDate . ' ' . $r['scan_3']);
            $t4 = strtotime($scanDate . ' ' . $r['scan_4']);
            if ($t4 > $t3) $sh2 = round(($t4 - $t3) / 3600, 2);
        }
        $wh = round(($sh1 ?? 0) + ($sh2 ?? 0), 2);
        dbExec(
            $db,
            "UPDATE payroll_attendance SET shift_1_hours = ?, shift_2_hours = ?, work_hours = ? WHERE id = ?",
            [$sh1, $sh2, $wh, $r['id']]
        );
    }
}

// ── Helper: Get attendance hours from fingerprint/GPS data for a month ──
// Overtime (OT)   = HANYA jika ada overtime_request approved (dibulatkan jam penuh).
// Extra Hari      = jam dari hari kerja ke-27 dan seterusnya (>26 hari/bulan).
//                    Hari extra dibayar dgn rate jam = base/200, bukan butuh approval.
function getAttendanceHours($db, $empId, $month, $year)
{
    $monthStr = sprintf('%04d-%02d', $year, $month);
    $rows = $db->fetchAll(
        "SELECT work_hours, overtime_hours, shift_1_hours, shift_2_hours, check_in_time, check_out_time, scan_3, scan_4, attendance_date
         FROM payroll_attendance 
         WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
         AND (work_hours > 0 OR check_in_time IS NOT NULL)
         ORDER BY attendance_date ASC",
        [$empId, $monthStr]
    );

    // Get all approved overtime dates for this employee in this month
    $approvedOTDates = [];
    try {
        $otRows = $db->fetchAll(
            "SELECT overtime_date FROM overtime_requests WHERE employee_id = ? AND status = 'approved' AND DATE_FORMAT(overtime_date, '%Y-%m') = ?",
            [$empId, $monthStr]
        );
        foreach ($otRows ?: [] as $otRow) {
            $approvedOTDates[$otRow['overtime_date']] = true;
        }
    } catch (\Throwable $e) {
        // Table might not exist yet — treat as no approved OT
    }

    $standardWorkDays = 26; // batas hari kerja standar/bulan; hari ke-27+ = Extra
    $totalHours = 0;
    $totalOvertimeHours = 0;
    $extraHours = 0;
    $extraDays = 0;
    $daysWorked = 0;
    foreach ($rows as $r) {
        $wh = (float)$r['work_hours'];
        $manualOT = (float)($r['overtime_hours'] ?? 0);
        // If work_hours not stored, compute from scan timestamps
        if ($wh <= 0) {
            $shift1 = 0;
            $shift2 = 0;
            // Shift 1: scan1 (check_in_time) → scan2 (check_out_time)
            if (!empty($r['shift_1_hours']) && (float)$r['shift_1_hours'] > 0) {
                $shift1 = (float)$r['shift_1_hours'];
            } elseif (!empty($r['check_in_time']) && !empty($r['check_out_time'])) {
                $t1 = strtotime($r['check_in_time']);
                $t2 = strtotime($r['check_out_time']);
                if ($t2 > $t1) $shift1 = round(($t2 - $t1) / 3600, 2);
            }
            // Shift 2: scan3 → scan4
            if (!empty($r['shift_2_hours']) && (float)$r['shift_2_hours'] > 0) {
                $shift2 = (float)$r['shift_2_hours'];
            } elseif (!empty($r['scan_3']) && !empty($r['scan_4'])) {
                $t3 = strtotime($r['scan_3']);
                $t4 = strtotime($r['scan_4']);
                if ($t4 > $t3) $shift2 = round(($t4 - $t3) / 3600, 2);
            }
            $wh = round($shift1 + $shift2, 2);
            if ($wh <= 0) continue; // no usable scan data for this day
        }
        $daysWorked++;
        $cappedDay = min($wh, 8); // jam regular maksimal per hari = 8

        if ($daysWorked <= $standardWorkDays) {
            // Hari 1..26 → masuk jam regular (gaji pokok)
            $totalHours += $cappedDay;
        } else {
            // Hari 27+ → masuk Extra Hari (dibayar pakai rate OT, tanpa approval)
            $extraHours += $cappedDay;
            $extraDays++;
        }

        // OT (Overtime) HANYA dari overtime_request yg approved atau manual entry.
        // Rule: OT dibulatkan ke jam penuh (threshold 45 menit).
        $attDate = $r['attendance_date'] ?? '';
        if ($manualOT > 0) {
            $totalOvertimeHours += roundOT45($manualOT);
        } elseif (isset($approvedOTDates[$attDate])) {
            $rawOT = max(0, $wh - 8);
            $totalOvertimeHours += roundOT45($rawOT);
        }
    }

    return [
        'work_hours'     => round($totalHours, 2),
        'overtime_hours' => round($totalOvertimeHours, 2),
        'extra_hours'    => round($extraHours, 2),
        'extra_days'     => $extraDays,
        'days_worked'    => $daysWorked,
        // back-compat
        'auto_overtime_over_200' => 0.0,
    ];
}

// ── Helper: Add any active employees not yet in this period's slips ──
// Fixes "staff baru tidak muncul di Process Salary" — sebelumnya staff baru
// hanya ditambahkan kalau admin klik tombol "Refresh" secara manual.
function autoAddMissingPayrollEmployees($db, $periodId, $month, $year)
{
    $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
    $existingEmpIds = array_column(
        $db->fetchAll("SELECT employee_id FROM payroll_slips WHERE period_id = ?", [$periodId]),
        'employee_id'
    );
    $added = 0;
    foreach ($employees as $emp) {
        if (in_array($emp['id'], $existingEmpIds, true)) continue;
        $att = getAttendanceHours($db, $emp['id'], $month, $year);
        $workH = $att['work_hours'] > 0 ? $att['work_hours'] : 0;
        $baseSalary = (float)$emp['base_salary'];
        $hourlyRate = $baseSalary / 200;
        $actualBase = $baseSalary; // gaji pokok penuh, tidak diprorata
        dbExec(
            $db,
            "INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary, work_hours, actual_base, overtime_hours, overtime_rate, overtime_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$periodId, $emp['id'], $emp['full_name'], $emp['position'], $baseSalary, $workH, $actualBase, $att['overtime_hours'], $hourlyRate, round($att['overtime_hours'] * $hourlyRate, 2)]
        );
        $added++;
    }
    return $added;
}

// ── Helper: Sync all slips with attendance data ──
function syncSlipsWithAttendance($db, $periodId, $month, $year)
{
    // Only sync slips that are NOT manually edited (hours_locked = 0)
    $slipsToSync = $db->fetchAll("SELECT id, employee_id, base_salary, hours_locked, work_hours, overtime_hours FROM payroll_slips WHERE period_id = ? AND hours_locked = 0", [$periodId]);
    foreach ($slipsToSync as $slip) {
        // Pull latest attendance hours from fingerprint/GPS data
        $att = getAttendanceHours($db, $slip['employee_id'], $month, $year);
        $workH = $att['work_hours'];
        // ONLY update work_hours from attendance — keep all other fields (overtime, incentive, etc.) as-is
        $baseSalary = (float)$slip['base_salary'];
        $hourlyRate = $baseSalary / 200;
        // Gaji pokok PENUH (gaji bulanan tetap) — tidak diprorata jam kerja.
        $actualBase = $baseSalary;

        // Read current addon values via direct PDO (preserve them, don't overwrite)
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare("SELECT overtime_hours, overtime_rate, overtime_amount, incentive, allowance, uang_makan, bonus, other_income, deduction_loan, deduction_absence, deduction_tax, deduction_bpjs, deduction_other FROM payroll_slips WHERE id = ?");
        $stmt->execute([$slip['id']]);
        $cur = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$cur) continue; // skip if slip disappeared

        $otH = (float)($att['overtime_hours'] ?? 0);
        $otRate = $hourlyRate;
        $otAmount = round($otH * $otRate, 2);
        $incentive = (float)($cur['incentive'] ?? 0);
        $allowance = (float)($cur['allowance'] ?? 0);
        $uang_makan = (float)($cur['uang_makan'] ?? 0);
        $bonus = (float)($cur['bonus'] ?? 0);
        $other = (float)($cur['other_income'] ?? 0);
        $totalEarn = $actualBase + $otAmount + $incentive + $allowance + $uang_makan + $bonus + $other;
        $loan = (float)($cur['deduction_loan'] ?? 0);
        $absence = (float)($cur['deduction_absence'] ?? 0);
        $tax = (float)($cur['deduction_tax'] ?? 0);
        $bpjs = (float)($cur['deduction_bpjs'] ?? 0);
        $dedOther = (float)($cur['deduction_other'] ?? 0);
        $totalDed = $loan + $absence + $tax + $bpjs + $dedOther;
        $netSalary = $totalEarn - $totalDed;

        // Update via direct PDO to avoid silent error swallowing
        // Sync overtime_hours too so the input field reflects the true total
        // (manual + approved request + auto-OT >200j), keeping UI consistent
        // with the overtime_amount that's actually paid.
        dbExec(
            $db,
            "UPDATE payroll_slips SET work_hours=?, overtime_hours=?, actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=? WHERE id=?",
            [$workH, $otH, $actualBase, $otRate, $otAmount, $totalEarn, $totalDed, $netSalary, $slip['id']]
        );
    }
    // Update period totals
    dbExec($db, "UPDATE payroll_periods p LEFT JOIN (SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ?) s ON p.id = s.period_id SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt WHERE p.id = ?", [$periodId, $periodId]);
}

// ── Handle manual sync from attendance ──
if (isset($_POST['sync_attendance']) && $period) {
    if ($isFrozen) {
        setFlash('error', '🔒 Periode bulan lalu dibekukan — Sync Absensi dinonaktifkan untuk menjaga total gaji historis.');
        header("Location: process.php?month=$month&year=$year");
        exit;
    }
    try {
        syncSlipsWithAttendance($db, $period['id'], $month, $year);
        setFlash('success', '✅ Jam kerja berhasil di-sync dari data absensi');
    } catch (\Throwable $e) {
        setFlash('error', 'Sync error: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

if (!$period && isset($_POST['create_period'])) {
    try {
        $label = $months[$month] . ' ' . $year;
        dbExec(
            $db,
            "INSERT INTO payroll_periods (period_month, period_year, period_label, status, created_by) VALUES (?, ?, ?, 'draft', ?)",
            [$month, $year, $label, $_SESSION['user_id']]
        );
        $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);

        $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
        foreach ($employees as $emp) {
            // Get real attendance hours for this month
            $att = getAttendanceHours($db, $emp['id'], $month, $year);
            $workH = $att['work_hours'] > 0 ? $att['work_hours'] : 0;
            $otH = $att['overtime_hours'];
            $baseSalary = (float)$emp['base_salary'];
            $hourlyRate = $baseSalary / 200;
            // Gaji pokok PENUH (gaji bulanan tetap) — tidak diprorata jam kerja.
            $actualBase = $baseSalary;
            $otRate = $hourlyRate;
            $otAmount = round($otH * $otRate, 2);
            $totalEarn = $actualBase + $otAmount;
            $netSalary = $totalEarn;
            dbExec(
                $db,
                "INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary, work_hours, actual_base, overtime_hours, overtime_rate, overtime_amount, total_earnings, net_salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$period['id'], $emp['id'], $emp['full_name'], $emp['position'], $baseSalary, $workH, $actualBase, $otH, $otRate, $otAmount, $totalEarn, $netSalary]
            );
        }

        setFlash('success', 'Payroll period created successfully');
        header("Location: process.php?month=$month&year=$year");
        exit;
    } catch (PDOException $e) {
        setFlash('error', 'Failed to create period: ' . $e->getMessage());
    }
}

// ── Handle AJAX Update FIRST (before auto-sync so edits are saved immediately) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    $slip_id = (int)$_POST['slip_id'];

    // base_salary HANYA boleh diedit dari Employee Data — abaikan POST,
    // ambil nilai master terkini dari payroll_employees (fallback ke slip lama).
    $base_salary = 0.0;
    try {
        $slipMaster = $db->fetchOne(
            "SELECT COALESCE(e.base_salary, s.base_salary, 0) AS bs
             FROM payroll_slips s
             LEFT JOIN payroll_employees e ON e.id = s.employee_id
             WHERE s.id = ?",
            [$slip_id]
        );
        $base_salary = (float)($slipMaster['bs'] ?? 0);
    } catch (Exception $e) {
        $base_salary = (float)($_POST['base_salary'] ?? 0);
    }
    $work_hours = (float)$_POST['work_hours'];
    $overtime_hours = (float)$_POST['overtime_hours'];
    $extra_hours = isset($_POST['extra_hours']) ? (float)$_POST['extra_hours'] : 0.0;
    $extra_was_posted = isset($_POST['extra_hours']);
    $incentive = (float)$_POST['incentive'];
    $allowance = (float)$_POST['allowance'];
    $bonus = (float)$_POST['bonus'];
    $other = (float)$_POST['other_income'];
    $uang_makan = isset($_POST['uang_makan']) ? (float)$_POST['uang_makan'] : 0;

    $loan = (float)$_POST['deduction_loan'];
    $absence = (float)$_POST['deduction_absence'];
    $tax = (float)$_POST['deduction_tax'];
    $bpjs = (float)$_POST['deduction_bpjs'];
    $ded_other = (float)$_POST['deduction_other'];

    // Gaji pokok PENUH (gaji bulanan tetap) — tidak diprorata jam kerja.
    $hourly_rate = $base_salary / 200;
    $actual_base = $base_salary;

    // Overtime still uses same rate; Extra Hari pakai rate yg sama
    $overtime_rate = $hourly_rate;
    $overtime_amount = $overtime_hours * $overtime_rate;
    $extra_amount   = $extra_hours * $hourly_rate;

    $total_earnings = $actual_base + $overtime_amount + $extra_amount + $incentive + $allowance + $uang_makan + $bonus + $other;
    $total_deductions = $loan + $absence + $tax + $bpjs + $ded_other;
    $net_salary = $total_earnings - $total_deductions;

    try {
        $extraLockedFlag = $extra_was_posted ? 1 : 0;
        $sql = "UPDATE payroll_slips SET 
                base_salary = ?, work_hours = ?, actual_base = ?,
                overtime_hours = ?, overtime_rate = ?, overtime_amount = ?,
                extra_hours = ?, extra_locked = GREATEST(extra_locked, ?),
                incentive = ?, allowance = ?, uang_makan = ?, bonus = ?, other_income = ?,
                deduction_loan = ?, deduction_absence = ?, deduction_tax = ?, deduction_bpjs = ?, deduction_other = ?,
                total_earnings = ?, total_deductions = ?, net_salary = ?,
                hours_locked = 1
                WHERE id = ?";

        dbExec($db, $sql, [
            $base_salary,
            $work_hours,
            $actual_base,
            $overtime_hours,
            $overtime_rate,
            $overtime_amount,
            $extra_hours,
            $extraLockedFlag,
            $incentive,
            $allowance,
            $uang_makan,
            $bonus,
            $other,
            $loan,
            $absence,
            $tax,
            $bpjs,
            $ded_other,
            $total_earnings,
            $total_deductions,
            $net_salary,
            $slip_id
        ]);

        $period_id = $period ? $period['id'] : 0;
        if ($period_id) {
            dbExec($db, "UPDATE payroll_periods p
                        LEFT JOIN (
                            SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt 
                            FROM payroll_slips WHERE period_id = ?
                        ) s ON p.id = s.period_id
                        SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt
                        WHERE p.id = ?", [$period_id, $period_id]);
        }

        echo json_encode(['status' => 'success', 'net_salary' => $net_salary, 'actual_base' => $actual_base, 'hours_locked' => 1]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Unlock Hours AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_unlock_hours'])) {
    header('Content-Type: application/json');
    $slip_id = (int)$_POST['slip_id'];
    try {
        $slip = $db->fetchOne("SELECT employee_id FROM payroll_slips WHERE id = ?", [$slip_id]);
        if (!$slip) throw new Exception('Slip not found');
        $att = getAttendanceHours($db, $slip['employee_id'], $month, $year);
        dbExec(
            $db,
            "UPDATE payroll_slips SET hours_locked = 0, work_hours = ?, overtime_hours = ? WHERE id = ?",
            [$att['work_hours'], $att['overtime_hours'], $slip_id]
        );
        echo json_encode(['status' => 'success', 'work_hours' => $att['work_hours'], 'overtime_hours' => $att['overtime_hours']]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Unlock Extra Hours AJAX (reset Extra >26hr to auto from attendance)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_unlock_extra'])) {
    header('Content-Type: application/json');
    $slip_id = (int)$_POST['slip_id'];
    try {
        $slip = $db->fetchOne("SELECT employee_id FROM payroll_slips WHERE id = ?", [$slip_id]);
        if (!$slip) throw new Exception('Slip not found');
        $att = getAttendanceHours($db, $slip['employee_id'], $month, $year);
        $autoExtra = (float)($att['extra_hours'] ?? 0);
        dbExec(
            $db,
            "UPDATE payroll_slips SET extra_locked = 0, extra_hours = ? WHERE id = ?",
            [$autoExtra, $slip_id]
        );
        echo json_encode(['status' => 'success', 'extra_hours' => $autoExtra]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══ AJAX: Save Daily Attendance (editable from modal) ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_daily_attendance'])) {
    header('Content-Type: application/json');
    try {
        $empId = (int)$_POST['employee_id'];
        $rows = json_decode($_POST['rows'], true);
        if (!$rows || !is_array($rows)) throw new Exception('Invalid data');

        foreach ($rows as $r) {
            $date = $r['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $checkIn  = !empty($r['check_in'])  ? $r['check_in']  : null;
            $checkOut = !empty($r['check_out']) ? $r['check_out'] : null;
            $scan3    = !empty($r['scan_3'])    ? $r['scan_3']    : null;
            $scan4    = !empty($r['scan_4'])    ? $r['scan_4']    : null;
            $status   = !empty($r['status'])    ? $r['status']    : 'present';

            // Skip completely empty rows
            if (!$checkIn && !$checkOut && !$scan3 && !$scan4 && $status === 'absent') {
                // Delete record if exists and user set to absent with no times
                dbExec($db, "DELETE FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ? AND check_in_time IS NULL", [$empId, $date]);
                continue;
            }
            if (!$checkIn && !$checkOut && !$scan3 && !$scan4) continue;

            // Compute hours from timestamps
            $shift1Hours = 0;
            $shift2Hours = 0;
            if ($checkIn && $checkOut) {
                $t1 = strtotime("2000-01-01 $checkIn");
                $t2 = strtotime("2000-01-01 $checkOut");
                if ($t2 > $t1) $shift1Hours = round(($t2 - $t1) / 3600, 2);
            }
            if ($scan3 && $scan4) {
                $t3 = strtotime("2000-01-01 $scan3");
                $t4 = strtotime("2000-01-01 $scan4");
                if ($t4 > $t3) $shift2Hours = round(($t4 - $t3) / 3600, 2);
            }
            $workHours = round($shift1Hours + $shift2Hours, 2);

            dbExec(
                $db,
                "INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_out_time, scan_3, scan_4, shift_1_hours, shift_2_hours, work_hours, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE check_in_time=VALUES(check_in_time), check_out_time=VALUES(check_out_time),
                    scan_3=VALUES(scan_3), scan_4=VALUES(scan_4), shift_1_hours=VALUES(shift_1_hours),
                    shift_2_hours=VALUES(shift_2_hours), work_hours=VALUES(work_hours), status=VALUES(status)",
                [$empId, $date, $checkIn, $checkOut, $scan3, $scan4, $shift1Hours, $shift2Hours, $workHours, $status]
            );
        }

        // Recalculate slip totals
        $att = getAttendanceHours($db, $empId, $month, $year);
        $slipData = null;
        if ($period) {
            $slip = $db->fetchOne("SELECT * FROM payroll_slips WHERE period_id = ? AND employee_id = ?", [$period['id'], $empId]);
            if ($slip) {
                $workH = $att['work_hours'];
                $otH = $att['overtime_hours'];
                $baseSalary = (float)$slip['base_salary'];
                $hourlyRate = $baseSalary / 200;
                $actualBase = ($workH >= 200) ? $baseSalary : round($workH * $hourlyRate, 2);
                $otAmount = round($otH * $hourlyRate, 2);
                $totalEarn = $actualBase + $otAmount + (float)($slip['incentive'] ?? 0) + (float)($slip['allowance'] ?? 0) + (float)($slip['uang_makan'] ?? 0) + (float)($slip['bonus'] ?? 0) + (float)($slip['other_income'] ?? 0);
                $totalDed = (float)($slip['deduction_loan'] ?? 0) + (float)($slip['deduction_absence'] ?? 0) + (float)($slip['deduction_tax'] ?? 0) + (float)($slip['deduction_bpjs'] ?? 0) + (float)($slip['deduction_other'] ?? 0);
                $netSalary = $totalEarn - $totalDed;
                // Only update work_hours and recalculated totals — preserve hours_locked state
                dbExec(
                    $db,
                    "UPDATE payroll_slips SET work_hours=?, overtime_hours=?, actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=? WHERE id=?",
                    [$workH, $otH, $actualBase, $hourlyRate, $otAmount, $totalEarn, $totalDed, $netSalary, $slip['id']]
                );
                $slipData = ['slip_id' => $slip['id'], 'work_hours' => $workH, 'overtime_hours' => $otH, 'actual_base' => $actualBase, 'net_salary' => $netSalary];
            }
        }
        echo json_encode(['status' => 'success', 'work_hours' => $att['work_hours'], 'overtime_hours' => $att['overtime_hours'], 'slip' => $slipData]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$slips = [];
$autoSyncError = '';
if ($period) {
    // Auto-sync HANYA untuk bulan berjalan / masa depan dan status masih bisa diedit.
    // Periode bulan-bulan sebelumnya selalu dibekukan apapun statusnya
    // (lihat $isFrozen yang didefinisikan di atas — setelah deklarasi $month/$year).
    if (!$isFrozen && ($period['status'] === 'draft' || $period['status'] === 'submitted' || $period['status'] === 'approved')) {
        try {
            // Step 1: Recalculate ALL work_hours from scan timestamps (fix any stale data)
            recalcAttendanceHours($db, $month, $year);
            // Step 1b: Auto-add any newly active employees not yet in this period
            autoAddMissingPayrollEmployees($db, $period['id'], $month, $year);
            // Step 2: Sync attendance totals into salary slips (skips manually-edited slips where hours_locked=1)
            syncSlipsWithAttendance($db, $period['id'], $month, $year);
            // Refresh period data after sync
            $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE id = ?", [$period['id']]);
        } catch (\Throwable $e) {
            $autoSyncError = $e->getMessage();
            error_log('Payroll auto-sync error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // Fetch slips AFTER sync so we get updated work hours.
    // base_salary SELALU diambil dari payroll_employees (single source of truth)
    // sehingga edit di Employee Data langsung terlihat di Process Salary,
    // tidak peduli hours_locked atau status frozen.
    $slips = $db->fetchAll(
        "
        SELECT s.*, e.employee_code, e.department,
               COALESCE(e.base_salary, s.base_salary, 0) AS base_salary
        FROM payroll_slips s 
        JOIN payroll_employees e ON s.employee_id = e.id 
        WHERE s.period_id = ?
        ORDER BY s.employee_name ASC",
        [$period['id']]
    );

    // Pre-fetch Extra Hari (>26 hari) per karyawan dulu supaya bisa dipakai
    // saat re-hitung Net.
    $extraMap = [];
    foreach ($slips as $s) {
        try {
            $att = getAttendanceHours($db, (int)$s['employee_id'], $month, $year);
            $extraMap[(int)$s['employee_id']] = [
                'hours' => (float)($att['extra_hours'] ?? 0),
                'days'  => (int)($att['extra_days'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $extraMap[(int)$s['employee_id']] = ['hours' => 0, 'days' => 0];
        }
    }

    // Sinkronkan & RE-HITUNG semua angka turunan (actual_base, OT amount,
    // total_earnings, total_deductions, net_salary) dari komponen masing-masing
    // supaya tampilan Net SELALU = actual_base + OT Rp + Extra Rp + service + allowance
    // + bonus + other_income - (loan+absence+tax+bpjs+other).
    // Untuk periode FROZEN tetap di-rekalkulasi UNTUK TAMPILAN tapi tidak menulis ke DB.
    foreach ($slips as $i => $s) {
        $masterBase = (float)$s['base_salary']; // sudah COALESCE master
        $wh = (float)$s['work_hours'];
        $oh = (float)$s['overtime_hours'];
        $hourly = $masterBase > 0 ? $masterBase / 200 : 0;
        $actualBase = ($wh >= 200) ? $masterBase : round($wh * $hourly, 2);
        $otAmount   = round($oh * $hourly, 2);
        $autoExtraH  = (float)($extraMap[(int)$s['employee_id']]['hours'] ?? 0);
        $extraLocked = !empty($s['extra_locked']);
        $extraH      = $extraLocked ? (float)($s['extra_hours'] ?? 0) : $autoExtraH;
        $extraAmount = round($extraH * $hourly, 2);
        $incentive  = (float)$s['incentive'];
        $allowance  = (float)$s['allowance'];
        $uangMakan  = 0.0; // Uang makan dihilangkan dari perhitungan Net
        $bonus      = (float)$s['bonus'];
        $otherInc   = (float)$s['other_income'];
        $loan       = (float)($s['deduction_loan'] ?? 0);
        $absence    = (float)($s['deduction_absence'] ?? 0);
        $tax        = (float)($s['deduction_tax'] ?? 0);
        $bpjs       = (float)($s['deduction_bpjs'] ?? 0);
        $dedOther   = (float)($s['deduction_other'] ?? 0);
        $totalDed   = $loan + $absence + $tax + $bpjs + $dedOther;
        $totalEarn  = $actualBase + $otAmount + $extraAmount + $incentive + $allowance + $uangMakan + $bonus + $otherInc;
        $netSal     = $totalEarn - $totalDed;

        // Update display values
        $slips[$i]['actual_base']      = $actualBase;
        $slips[$i]['overtime_rate']    = $hourly;
        $slips[$i]['overtime_amount']  = $otAmount;
        $slips[$i]['extra_hours']      = $extraH;
        $slips[$i]['extra_amount']     = $extraAmount;
        $slips[$i]['total_earnings']   = $totalEarn;
        $slips[$i]['total_deductions'] = $totalDed;
        $slips[$i]['net_salary']       = $netSal;

        // Tulis ke DB HANYA bila tidak frozen
        if (!$isFrozen) {
            $oldNet      = (float)$s['net_salary'];
            $oldActual   = (float)$s['actual_base'];
            $oldOtAmt    = (float)$s['overtime_amount'];
            $oldEarn     = (float)$s['total_earnings'];
            $oldDed      = (float)($s['total_deductions'] ?? 0);
            $needUpdate  = (abs($oldNet - $netSal) > 0.5) || (abs($oldActual - $actualBase) > 0.5)
                || (abs($oldOtAmt - $otAmount) > 0.5) || (abs($oldEarn - $totalEarn) > 0.5)
                || (abs($oldDed - $totalDed) > 0.5);
            if ($needUpdate) {
                try {
                    dbExec(
                        $db,
                        "UPDATE payroll_slips
                         SET base_salary=?, actual_base=?, overtime_rate=?, overtime_amount=?,
                             extra_hours=?,
                             total_earnings=?, total_deductions=?, net_salary=?, uang_makan=0
                         WHERE id=?",
                        [$masterBase, $actualBase, $hourly, $otAmount, $extraH, $totalEarn, $totalDed, $netSal, $s['id']]
                    );
                } catch (Exception $e) { /* abaikan */
                }
            }
        }
    }

    // Ensure displayed period totals are in sync with payroll_slips sums
    try {
        $sums = $db->fetchOne("SELECT IFNULL(SUM(total_earnings),0) as gross, IFNULL(SUM(total_deductions),0) as ded, IFNULL(SUM(net_salary),0) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ?", [$period['id']]);
        if ($sums) {
            $period['total_gross'] = $sums['gross'];
            $period['total_deductions'] = $sums['ded'];
            $period['total_net'] = $sums['net'];
            $period['total_employees'] = $sums['cnt'];
            // Persist to payroll_periods to keep DB consistent
            dbExec($db, "UPDATE payroll_periods SET total_gross = ?, total_deductions = ?, total_net = ?, total_employees = ? WHERE id = ?", [$sums['gross'], $sums['ded'], $sums['net'], $sums['cnt'], $period['id']]);
        }
    } catch (\Throwable $e) {
        // ignore sync errors; page can still render with existing period values
    }
}

// Handle Save/Proses Button (recalculate all slips and update period net total)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_proses'])) {
    if ($period) {
        $slips_recalc = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ?", [$period['id']]);
        foreach ($slips_recalc as $slip) {
            $base_salary = (float)$slip['base_salary'];
            $work_hours = (float)$slip['work_hours'];
            $overtime_hours = (float)$slip['overtime_hours'];
            $incentive = (float)$slip['incentive'];
            $allowance = (float)$slip['allowance'];
            $bonus = (float)$slip['bonus'];
            $other = (float)$slip['other_income'];
            $uang_makan = isset($slip['uang_makan']) ? (float)$slip['uang_makan'] : 0;
            $loan = (float)$slip['deduction_loan'];
            $absence = (float)$slip['deduction_absence'];
            $tax = (float)$slip['deduction_tax'];
            $bpjs = (float)$slip['deduction_bpjs'];
            $ded_other = (float)$slip['deduction_other'];
            $hourly_rate = $base_salary / 200;
            $actual_base = ($work_hours >= 200) ? $base_salary : $work_hours * $hourly_rate;
            $overtime_rate = $hourly_rate;
            $overtime_amount = $overtime_hours * $overtime_rate;
            $total_earnings = $actual_base + $overtime_amount + $incentive + $allowance + $uang_makan + $bonus + $other;
            $total_deductions = $loan + $absence + $tax + $bpjs + $ded_other;
            $net_salary = $total_earnings - $total_deductions;
            dbExec(
                $db,
                "UPDATE payroll_slips SET actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=?, uang_makan=? WHERE id=?",
                [$actual_base, $overtime_rate, $overtime_amount, $total_earnings, $total_deductions, $net_salary, $uang_makan, $slip['id']]
            );
        }
        $period_id = $period['id'];
        dbExec($db, "UPDATE payroll_periods p LEFT JOIN ( SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ? ) s ON p.id = s.period_id SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt WHERE p.id = ?", [$period_id, $period_id]);
        setFlash('success', 'All slips recalculated and totals updated!');
        header("Location: process.php?month=$month&year=$year");
        exit;
    }
}

// Handle Submit Period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_period'])) {
    dbExec(
        $db,
        "UPDATE payroll_periods SET status = 'submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ?",
        [$_SESSION['user_id'], $period['id']]
    );
    setFlash('success', 'Payroll submitted to Owner for approval');
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Approve Period (Owner) - Record to Cashbook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_period'])) {
    try {
        dbExec(
            $db,
            "UPDATE payroll_periods SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?",
            [$_SESSION['user_id'], $period['id']]
        );

        $periodLabel = $months[$period['period_month']] . ' ' . $period['period_year'];
        $description = 'Payroll ' . $periodLabel . ' - Bank Transfer';
        $amount = $period['total_net'];

        $bankAccount = $db->fetchOne("SELECT id FROM cash_accounts WHERE (account_name LIKE '%Bank%' OR account_name LIKE '%BCA%' OR account_name LIKE '%BRI%') AND is_active = 1 LIMIT 1");
        $accountId = $bankAccount ? $bankAccount['id'] : null;

        // Record to cashbook (non-blocking)
        try {
            $div = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR id = 1 LIMIT 1");
            $cat = $db->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%gaji%' OR LOWER(category_name) LIKE '%payroll%' OR LOWER(category_name) LIKE '%salary%' OR id = 1 LIMIT 1");
            $txNum = 'PAY-' . date('Ymd') . '-' . $period['id'];
            dbExec(
                $db,
                "INSERT INTO cash_book (transaction_date, transaction_time, transaction_number, division_id, category_id, account_id, transaction_type, amount, description, reference_number, payment_method, status, created_by) 
                 VALUES (CURDATE(), CURTIME(), ?, ?, ?, ?, 'expense', ?, ?, ?, 'bank_transfer', 'posted', ?)",
                [$txNum, $div['id'] ?? 1, $cat['id'] ?? 1, $accountId, $amount, $description, 'PAYROLL-' . $period['id'], $_SESSION['user_id']]
            );
        } catch (\Throwable $cbErr) {
            error_log('Cashbook record skipped (approve): ' . $cbErr->getMessage());
        }

        setFlash('success', 'Payroll approved! Rp ' . number_format($amount, 0, ',', '.') . ' recorded to cashbook.');
    } catch (\Throwable $e) {
        setFlash('error', 'Error approving payroll: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Mark as Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    try {
        dbExec($db, "UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$period['id']]);
        setFlash('success', 'Payroll marked as Paid');
    } catch (\Throwable $e) {
        error_log("mark_paid error: " . $e->getMessage());
        setFlash('error', 'Error marking as paid: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Quick Pay — Save + Approve + Record Cashbook + Mark Paid in one step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_pay'])) {
    try {
        // 1. Recalculate ALL slips from their individual fields (fix any stale totals)
        $allSlips = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ?", [$period['id']]) ?: [];
        $totalNet = 0;
        foreach ($allSlips as $sl) {
            $bs = (float)$sl['base_salary'];
            $wh = (float)$sl['work_hours'];
            $hr = $bs / 200;
            $ab = ($wh >= 200) ? $bs : round($wh * $hr, 2);
            $oH = (float)$sl['overtime_hours'];
            $oA = round($oH * $hr, 2);
            $tE = $ab + $oA + (float)($sl['incentive'] ?? 0) + (float)($sl['allowance'] ?? 0) + (float)($sl['uang_makan'] ?? 0) + (float)($sl['bonus'] ?? 0) + (float)($sl['other_income'] ?? 0);
            $tD = (float)($sl['deduction_loan'] ?? 0) + (float)($sl['deduction_absence'] ?? 0) + (float)($sl['deduction_tax'] ?? 0) + (float)($sl['deduction_bpjs'] ?? 0) + (float)($sl['deduction_other'] ?? 0);
            $nS = $tE - $tD;
            dbExec(
                $db,
                "UPDATE payroll_slips SET actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=? WHERE id=?",
                [$ab, $hr, $oA, $tE, $tD, $nS, $sl['id']]
            );
            $totalNet += $nS;
        }

        // 2. Update period totals
        dbExec(
            $db,
            "UPDATE payroll_periods SET total_net = ?, total_employees = ? WHERE id = ?",
            [$totalNet, count($allSlips), $period['id']]
        );

        // 3. Skip to approved + cashbook
        dbExec(
            $db,
            "UPDATE payroll_periods SET status = 'approved', submitted_at = NOW(), submitted_by = ?, approved_at = NOW(), approved_by = ? WHERE id = ?",
            [$_SESSION['user_id'], $_SESSION['user_id'], $period['id']]
        );

        $periodLabel = $months[$period['period_month']] . ' ' . $period['period_year'];
        $description = 'Payroll ' . $periodLabel . ' - Bank Transfer';
        $amount = $totalNet ?: $period['total_net'];

        $bankAccount = $db->fetchOne("SELECT id FROM cash_accounts WHERE (account_name LIKE '%Bank%' OR account_name LIKE '%BCA%' OR account_name LIKE '%BRI%') AND is_active = 1 LIMIT 1");
        $accountId = $bankAccount ? $bankAccount['id'] : null;

        // Record to cashbook (non-blocking)
        try {
            $existing = $db->fetchOne("SELECT id FROM cash_book WHERE reference_number = ?", ['PAYROLL-' . $period['id']]);
            if (!$existing) {
                $div = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR id = 1 LIMIT 1");
                $cat = $db->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%gaji%' OR LOWER(category_name) LIKE '%payroll%' OR LOWER(category_name) LIKE '%salary%' OR id = 1 LIMIT 1");
                $txNum = 'PAY-' . date('Ymd') . '-' . $period['id'];
                dbExec(
                    $db,
                    "INSERT INTO cash_book (transaction_date, transaction_time, transaction_number, division_id, category_id, account_id, transaction_type, amount, description, reference_number, payment_method, status, created_by) 
                     VALUES (CURDATE(), CURTIME(), ?, ?, ?, ?, 'expense', ?, ?, ?, 'bank_transfer', 'posted', ?)",
                    [$txNum, $div['id'] ?? 1, $cat['id'] ?? 1, $accountId, $amount, $description, 'PAYROLL-' . $period['id'], $_SESSION['user_id']]
                );
            }
        } catch (\Throwable $cbErr) {
            error_log('Cashbook record skipped (quick_pay): ' . $cbErr->getMessage());
        }

        // 4. Mark as paid + mark all slips as is_paid
        dbExec($db, "UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$period['id']]);
        dbExec($db, "UPDATE payroll_slips SET is_paid = 1 WHERE period_id = ?", [$period['id']]);

        setFlash('success', '✅ Payroll dibayar! Rp ' . number_format($amount, 0, ',', '.') . ' tercatat di cashbook. Slip gaji tersedia di Staff Portal.');
    } catch (\Throwable $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Quick Pay Selected — pay individual employees
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_pay_selected'])) {
    $selectedIds = $_POST['selected_slips'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $selectedIds)));

    if (empty($ids)) {
        setFlash('error', 'Tidak ada staff yang dipilih');
        header("Location: process.php?month=$month&year=$year");
        exit;
    }

    try {
        // Recalculate selected slips before payment
        foreach ($ids as $sid) {
            $sl = $db->fetchOne("SELECT * FROM payroll_slips WHERE id = ?", [$sid]);
            if (!$sl) continue;
            $bs = (float)$sl['base_salary'];
            $wh = (float)$sl['work_hours'];
            $hr = $bs / 200;
            $ab = ($wh >= 200) ? $bs : round($wh * $hr, 2);
            $oH = (float)$sl['overtime_hours'];
            $oA = round($oH * $hr, 2);
            $tE = $ab + $oA + (float)($sl['incentive'] ?? 0) + (float)($sl['allowance'] ?? 0) + (float)($sl['uang_makan'] ?? 0) + (float)($sl['bonus'] ?? 0) + (float)($sl['other_income'] ?? 0);
            $tD = (float)($sl['deduction_loan'] ?? 0) + (float)($sl['deduction_absence'] ?? 0) + (float)($sl['deduction_tax'] ?? 0) + (float)($sl['deduction_bpjs'] ?? 0) + (float)($sl['deduction_other'] ?? 0);
            $nS = $tE - $tD;
            dbExec(
                $db,
                "UPDATE payroll_slips SET actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=? WHERE id=?",
                [$ab, $hr, $oA, $tE, $tD, $nS, $sid]
            );
        }

        // Ensure period is at least approved (so portal can see it)
        if ($period['status'] === 'draft') {
            dbExec(
                $db,
                "UPDATE payroll_periods SET status = 'approved', submitted_at = NOW(), submitted_by = ?, approved_at = NOW(), approved_by = ? WHERE id = ?",
                [$_SESSION['user_id'], $_SESSION['user_id'], $period['id']]
            );
        } elseif ($period['status'] === 'submitted') {
            dbExec(
                $db,
                "UPDATE payroll_periods SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?",
                [$_SESSION['user_id'], $period['id']]
            );
        }

        // Mark selected slips as paid
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$period['id']]);
        dbExec($db, "UPDATE payroll_slips SET is_paid = 1 WHERE id IN ($placeholders) AND period_id = ?", $params);

        // Calculate total for selected
        $selectedSlips = $db->fetchAll("SELECT net_salary, employee_name FROM payroll_slips WHERE id IN ($placeholders)", $ids);
        $totalPaid = 0;
        $names = [];
        foreach ($selectedSlips as $s) {
            $totalPaid += (float)$s['net_salary'];
            $names[] = $s['employee_name'];
        }

        // Record to cashbook
        $periodLabel = $months[$period['period_month']] . ' ' . $period['period_year'];
        $description = 'Gaji ' . implode(', ', $names) . ' - ' . $periodLabel;
        if (strlen($description) > 200) $description = 'Gaji ' . count($names) . ' staff - ' . $periodLabel;

        $bankAccount = $db->fetchOne("SELECT id FROM cash_accounts WHERE (account_name LIKE '%Bank%' OR account_name LIKE '%BCA%' OR account_name LIKE '%BRI%') AND is_active = 1 LIMIT 1");
        $accountId = $bankAccount ? $bankAccount['id'] : null;

        $ref = 'PAYROLL-' . $period['id'] . '-' . implode('_', $ids);
        // Record to cashbook (non-blocking)
        try {
            $div = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR id = 1 LIMIT 1");
            $cat = $db->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%gaji%' OR LOWER(category_name) LIKE '%payroll%' OR LOWER(category_name) LIKE '%salary%' OR id = 1 LIMIT 1");
            $txNum = 'PAY-' . date('Ymd') . '-' . substr(md5($ref), 0, 6);
            dbExec(
                $db,
                "INSERT INTO cash_book (transaction_date, transaction_time, transaction_number, division_id, category_id, account_id, transaction_type, amount, description, reference_number, payment_method, status, created_by) 
                 VALUES (CURDATE(), CURTIME(), ?, ?, ?, ?, 'expense', ?, ?, ?, 'bank_transfer', 'posted', ?)",
                [$txNum, $div['id'] ?? 1, $cat['id'] ?? 1, $accountId, $totalPaid, $description, $ref, $_SESSION['user_id']]
            );
        } catch (\Throwable $cbErr) {
            error_log('Cashbook record skipped (quick_pay_selected): ' . $cbErr->getMessage());
        }

        // Check if ALL slips in this period are now paid
        $unpaid = $db->fetchOne("SELECT COUNT(*) as c FROM payroll_slips WHERE period_id = ? AND is_paid = 0", [$period['id']]);
        if ((int)($unpaid['c'] ?? 0) === 0) {
            dbExec($db, "UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$period['id']]);
        }

        setFlash('success', '✅ ' . count($ids) . ' staff dibayar! Rp ' . number_format($totalPaid, 0, ',', '.') . ' tercatat. Slip gaji tersedia di Staff Portal.');
    } catch (\Throwable $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Reset Period — delete period and all slips, remove cashbook entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_period']) && $period) {
    try {
        $periodId = $period['id'];
        // Delete cashbook entries for this period (non-blocking if table/entry doesn't exist)
        try {
            dbExec($db, "DELETE FROM cash_book WHERE reference_number LIKE ?", ['PAYROLL-' . $periodId . '%']);
        } catch (\Throwable $cbErr) {
            error_log('Reset cashbook cleanup skipped: ' . $cbErr->getMessage());
        }
        // Delete all slips
        dbExec($db, "DELETE FROM payroll_slips WHERE period_id = ?", [$periodId]);
        // Delete period
        dbExec($db, "DELETE FROM payroll_periods WHERE id = ?", [$periodId]);
        setFlash('success', '🔄 Period ' . $months[$month] . ' ' . $year . ' berhasil di-reset. Silakan buat ulang.');
    } catch (\Throwable $e) {
        setFlash('error', 'Reset error: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Refresh Employees (Sync: add new, remove deleted, update info)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_employees'])) {
    $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
    $activeEmpIds = array_column($employees, 'id');

    // Remove slips for employees not in active list
    $existingSlips = $db->fetchAll("SELECT id, employee_id FROM payroll_slips WHERE period_id = ?", [$period['id']]);
    $removed = 0;
    foreach ($existingSlips as $slip) {
        if (!in_array($slip['employee_id'], $activeEmpIds)) {
            dbExec($db, "DELETE FROM payroll_slips WHERE id = ?", [$slip['id']]);
            $removed++;
        }
    }

    // Get updated existing IDs
    $existingEmpIds = $db->fetchAll("SELECT employee_id FROM payroll_slips WHERE period_id = ?", [$period['id']]);
    $existingIds = array_column($existingEmpIds, 'employee_id');

    // Add new employees
    $added = 0;
    foreach ($employees as $emp) {
        if (!in_array($emp['id'], $existingIds)) {
            $att = getAttendanceHours($db, $emp['id'], $month, $year);
            $workH = $att['work_hours'] > 0 ? $att['work_hours'] : 0;
            $baseSalary = (float)$emp['base_salary'];
            $hourlyRate = $baseSalary / 200;
            $actualBase = ($workH >= 200) ? $baseSalary : round($workH * $hourlyRate, 2);
            dbExec(
                $db,
                "INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary, work_hours, actual_base, overtime_hours, overtime_rate, overtime_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$period['id'], $emp['id'], $emp['full_name'], $emp['position'], $baseSalary, $workH, $actualBase, $att['overtime_hours'], $hourlyRate, round($att['overtime_hours'] * $hourlyRate, 2)]
            );
            $added++;
        }
    }

    // Update employee info (name, position) for existing slips
    foreach ($employees as $emp) {
        if (in_array($emp['id'], $existingIds)) {
            dbExec(
                $db,
                "UPDATE payroll_slips SET employee_name = ?, position = ? WHERE period_id = ? AND employee_id = ?",
                [$emp['full_name'], $emp['position'], $period['id'], $emp['id']]
            );
        }
    }

    // Sync attendance hours for all slips
    syncSlipsWithAttendance($db, $period['id'], $month, $year);

    $msg = [];
    if ($added > 0) $msg[] = "$added added";
    if ($removed > 0) $msg[] = "$removed removed";
    if (empty($msg)) {
        setFlash('info', 'Employee list is up to date');
    } else {
        setFlash('success', 'Employees synced: ' . implode(', ', $msg));
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

include '../../includes/header.php';
?>

<style>
    /* ══════════════════════════════════════════════════════════════════════════
   PROCESS SALARY 2027 - MODERN DESIGN
   ══════════════════════════════════════════════════════════════════════════ */
    :root {
        --ps-gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --ps-gradient-2: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
        --ps-radius: 16px;
        --ps-radius-sm: 10px;
    }

    .ps-page-wrapper {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0;
    }

    /* Header Hero */
    .ps-header {
        background: #ffffff;
        border-radius: var(--ps-radius);
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        position: relative;
        overflow: hidden;
        color: #1a1a2e !important;
        border: 2px solid #e5e7eb;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .ps-header>div {
        color: #1a1a2e !important;
    }

    .ps-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 250px;
        height: 250px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
        border-radius: 50%;
    }

    .ps-header h1 {
        color: #1a1a2e !important;
        font-size: 1.3rem;
        font-weight: 700;
        margin: 0;
        position: relative;
        z-index: 10;
        text-shadow: none;
    }

    .ps-header p {
        color: #4b5563 !important;
        margin: 0.15rem 0 0 !important;
        font-size: 0.95rem !important;
        font-weight: 600 !important;
        text-shadow: none !important;
        position: relative !important;
        z-index: 10 !important;
        display: block !important;
    }

    .ps-filter {
        display: flex;
        gap: 0.5rem;
        position: relative;
        z-index: 2;
    }

    .ps-filter select {
        padding: 0.5rem 0.75rem;
        border: none;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        font-size: 0.85rem;
        cursor: pointer;
        backdrop-filter: blur(10px);
    }

    .ps-filter select option {
        color: #333;
    }

    /* Status Bar */
    .ps-status-bar {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--ps-radius-sm);
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .ps-status-info {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .ps-status-badge {
        padding: 0.4rem 0.85rem;
        border-radius: 50px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .ps-status-badge.draft {
        background: rgba(156, 163, 175, 0.2);
        color: #6b7280;
    }

    .ps-status-badge.submitted {
        background: rgba(245, 158, 11, 0.2);
        color: #d97706;
    }

    .ps-status-badge.approved {
        background: rgba(34, 197, 94, 0.2);
        color: #22c55e;
    }

    .ps-status-badge.paid {
        background: rgba(139, 92, 246, 0.2);
        color: #8b5cf6;
    }

    .ps-total-net {
        font-size: 1.5rem;
        font-weight: 700;
        background: var(--ps-gradient-2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .ps-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .ps-btn {
        padding: 0.5rem 1rem;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: all 0.2s;
        text-decoration: none;
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
    }

    .ps-btn-warning {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #ffffff !important;
    }

    .ps-btn-success {
        background: linear-gradient(135deg, #22c55e, #16a34a);
        color: #ffffff !important;
    }

    .ps-btn-primary {
        background: var(--ps-gradient-1);
        color: #ffffff !important;
    }

    .ps-btn-secondary {
        background: linear-gradient(135deg, #6366f1, #7c3aed);
        color: #ffffff !important;
    }

    .ps-btn-outline {
        background: transparent;
        border: 1px solid var(--border-color);
        color: #ffffff !important;
    }

    .ps-btn-outline:hover {
        border-color: var(--primary-color);
        color: var(--primary-color) !important;
    }

    .ps-btn-refresh {
        background: linear-gradient(135deg, #06b6d4, #0891b2);
        color: #ffffff !important;
        border: none;
    }

    .ps-btn-refresh:hover {
        background: linear-gradient(135deg, #0891b2, #0e7490);
        transform: translateY(-2px);
    }

    .ps-btn-reset {
        background: linear-gradient(135deg, #f87171, #dc2626);
        color: #ffffff !important;
        border: none;
    }

    .ps-btn-reset:hover {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        transform: translateY(-2px);
    }

    .ps-btn svg {
        stroke: #ffffff !important;
        fill: none !important;
    }

    /* Table Card */
    .ps-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--ps-radius);
        overflow: hidden;
    }

    .ps-table-container {
        overflow-x: auto;
        overflow-y: auto;
        max-height: 65vh;
    }

    .ps-table-container::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    .ps-table-container::-webkit-scrollbar-track {
        background: var(--bg-secondary);
    }

    .ps-table-container::-webkit-scrollbar-thumb {
        background: var(--border-color);
        border-radius: 3px;
    }

    .ps-table {
        width: 100%;
        border-collapse: collapse;
        min-width: auto;
        table-layout: auto;
    }

    .ps-table th {
        padding: 0.52rem 0.32rem;
        text-align: center;
        font-size: 0.74rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.28px;
        color: var(--text-tertiary);
        background: var(--bg-secondary);
        border-bottom: 2px solid var(--border-color);
        position: sticky;
        top: 0;
        z-index: 10;
        white-space: nowrap;
    }

    .ps-table th.col-employee {
        text-align: left;
        width: 156px;
        min-width: 156px;
        position: sticky;
        left: 0;
        z-index: 15;
        background: var(--bg-secondary);
    }

    .ps-table td {
        padding: 0.45rem 0.28rem;
        border-bottom: 1px solid var(--border-light);
        vertical-align: middle;
        text-align: center;
        font-size: 0.8rem;
        font-weight: 500;
        font-variant-numeric: tabular-nums;
    }

    .ps-table td.col-employee {
        text-align: left;
        position: sticky;
        left: 0;
        background: var(--bg-primary);
        z-index: 5;
        border-right: 1px solid var(--border-color);
    }

    .ps-table tr:hover td {
        background: var(--bg-secondary);
    }

    .ps-table tr:hover td.col-employee {
        background: var(--bg-secondary);
    }

    .ps-emp-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.8rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ps-emp-pos {
        font-size: 0.68rem;
        color: var(--text-tertiary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Input Styling */
    .ps-input {
        width: 100%;
        padding: 0.34rem 0.24rem;
        border: 1px solid transparent;
        border-radius: 4px;
        background: transparent;
        font-size: 0.76rem;
        font-weight: 500;
        text-align: right;
        transition: all 0.2s;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
    }

    .ps-input:hover {
        background: var(--bg-tertiary);
    }

    .ps-input:focus {
        outline: none;
        border-color: var(--primary-color);
        background: var(--bg-secondary);
        box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
    }

    .ps-input.readonly {
        color: var(--text-tertiary);
        cursor: default;
    }

    .ps-input.highlight-hours {
        background: rgba(245, 158, 11, 0.15);
        border-color: rgba(245, 158, 11, 0.3);
        text-align: center;
        font-weight: 600;
        font-size: 0.76rem;
        padding: 0.34rem 0.24rem;
    }

    .ps-input.negative {
        color: #ef4444;
    }

    .ps-cell-calc {
        font-family: 'SF Mono', Monaco, monospace;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--text-secondary);
        font-variant-numeric: tabular-nums;
    }

    .ps-cell-net {
        font-weight: 700;
        font-size: 0.82rem;
        color: #f59e0b;
        font-variant-numeric: tabular-nums;
    }

    /* Save Indicator */
    .save-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.6rem;
        color: var(--text-tertiary);
        margin-left: 0.25rem;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .save-indicator.saving,
    .save-indicator.saved,
    .save-indicator.error {
        opacity: 1;
    }

    .save-indicator.saving {
        color: #6366f1;
    }

    .save-indicator.saved {
        color: #22c55e;
    }

    .save-indicator.error {
        color: #ef4444;
    }

    .save-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
        animation: pulse 1s infinite;
    }

    .save-dot.saved {
        animation: none;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.5;
            transform: scale(0.8);
        }
    }

    /* Elegant Table Enhancements */
    .ps-table tr {
        transition: background 0.15s ease;
    }

    .ps-table tr:nth-child(even) td {
        background: rgba(0, 0, 0, 0.02);
    }

    .ps-table tr:nth-child(even):hover td {
        background: var(--bg-secondary);
    }

    /* Empty State */
    .ps-empty {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .ps-empty-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 1rem;
        border-radius: 50%;
        background: var(--bg-secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-tertiary);
    }

    .ps-empty h3 {
        margin: 0 0 0.5rem;
        color: var(--text-secondary);
    }

    .ps-empty p {
        margin: 0 0 1.5rem;
        color: var(--text-tertiary);
    }

    /* Info Tooltip */
    .ps-info {
        font-size: 0.65rem;
        color: var(--text-tertiary);
        margin-top: 0.15rem;
        font-weight: 400;
        line-height: 1.1;
    }

    /* Modal */
    .ps-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 1000;
    }

    .ps-modal-overlay.active {
        display: flex;
    }

    .ps-modal {
        background: var(--bg-primary);
        border-radius: var(--ps-radius);
        width: 90%;
        max-width: 450px;
        padding: 1.5rem;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .ps-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
    }

    .ps-modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    .ps-modal-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        margin: 1rem 0;
        background: rgba(239, 68, 68, 0.08);
        border-radius: 8px;
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .ps-modal-total-amount {
        font-size: 1.1rem;
        font-weight: 700;
        color: #ef4444;
    }

    .ps-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    /* Edit Button */
    .ps-btn-edit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: var(--bg-secondary);
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.2s;
    }

    .ps-btn-edit:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
        background: rgba(102, 126, 234, 0.1);
        transform: scale(1.05);
    }

    .ps-modal-title {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .ps-modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--text-tertiary);
    }

    .ps-form-group {
        margin-bottom: 0.85rem;
    }

    .ps-form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        margin-bottom: 0.35rem;
        color: var(--text-secondary);
    }

    .ps-form-input {
        width: 100%;
        padding: 0.6rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.85rem;
        background: var(--bg-secondary);
    }

    .ps-form-input:focus {
        outline: none;
        border-color: var(--primary-color);
    }

    /* Employee Row with Attendance Button */
    .ps-emp-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.35rem;
    }

    .ps-emp-info {
        flex: 1;
        min-width: 0;
    }

    .ps-btn-attendance {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border: 1px solid var(--border-color);
        border-radius: 5px;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(147, 197, 253, 0.1));
        color: #3b82f6;
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }

    .ps-btn-attendance:hover {
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        color: #fff;
        transform: scale(1.1);
        border-color: #3b82f6;
    }

    /* Attendance Modal */
    .att-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 1100;
        padding: 1rem;
    }

    .att-modal-overlay.active {
        display: flex;
    }

    .att-modal {
        background: var(--bg-primary);
        border-radius: 16px;
        width: 100%;
        max-width: min(95vw, 1600px);
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
        display: flex;
        flex-direction: column;
    }

    .att-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(147, 197, 253, 0.05));
    }

    .att-modal-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .att-modal-title svg {
        color: #3b82f6;
    }

    .att-modal-body {
        padding: 1.25rem 1.5rem;
        overflow-y: auto;
        flex: 1;
    }

    /* Summary Cards */
    .att-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .att-summary-card {
        background: linear-gradient(135deg, var(--bg-secondary), rgba(255, 255, 255, 0.5));
        border: 1.5px solid var(--border-color);
        border-radius: 12px;
        padding: 1rem 0.75rem;
        text-align: center;
        transition: all 0.2s;
    }

    .att-summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        border-color: rgba(59, 130, 246, 0.3);
    }

    .att-summary-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .att-summary-label {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-tertiary);
        margin-top: 0.35rem;
        font-weight: 600;
    }

    .att-summary-card.primary .att-summary-value {
        color: #3b82f6;
    }

    .att-summary-card.success .att-summary-value {
        color: #22c55e;
    }

    .att-summary-card.warning .att-summary-value {
        color: #f59e0b;
    }

    .att-summary-card.danger .att-summary-value {
        color: #ef4444;
    }

    /* Calendar Grid */
    .att-calendar {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 4px;
        margin-top: 0.75rem;
    }

    .att-cal-header {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-tertiary);
        text-transform: uppercase;
        text-align: center;
        padding: 0.45rem;
    }

    .att-cal-day {
        aspect-ratio: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 0.85rem;
        background: var(--bg-secondary);
        border: 1px solid var(--border-light);
        position: relative;
        cursor: default;
        transition: all 0.15s;
    }

    .att-cal-day:hover {
        transform: scale(1.05);
    }

    .att-cal-day.weekend {
        background: rgba(156, 163, 175, 0.1);
    }

    .att-cal-day.empty {
        visibility: hidden;
    }

    .att-cal-day.present {
        background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(74, 222, 128, 0.1));
        border-color: rgba(34, 197, 94, 0.3);
    }

    .att-cal-day.late {
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(251, 191, 36, 0.1));
        border-color: rgba(245, 158, 11, 0.3);
    }

    .att-cal-day.absent {
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(248, 113, 113, 0.1));
        border-color: rgba(239, 68, 68, 0.3);
    }

    .att-cal-day.holiday,
    .att-cal-day.leave {
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.15), rgba(167, 139, 250, 0.1));
        border-color: rgba(139, 92, 246, 0.3);
    }

    .att-cal-date {
        font-weight: 600;
        color: var(--text-primary);
    }

    .att-cal-hours {
        font-size: 0.85rem;
        color: var(--text-tertiary);
        margin-top: 4px;
    }

    .att-cal-status {
        position: absolute;
        top: 3px;
        right: 3px;
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }

    .att-cal-day.present .att-cal-status {
        background: #22c55e;
    }

    .att-cal-day.late .att-cal-status {
        background: #f59e0b;
    }

    .att-cal-day.absent .att-cal-status {
        background: #ef4444;
    }

    /* Attendance Table */
    .att-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
        margin-top: 1rem;
    }

    .att-table th {
        padding: 0.75rem 0.75rem;
        text-align: left;
        font-size: 0.9rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: var(--text-tertiary);
        border-bottom: 2px solid var(--border-color);
        background: linear-gradient(135deg, var(--bg-secondary), rgba(255, 255, 255, 0.3));
    }

    .att-table td {
        padding: 0.75rem 0.75rem;
        border-bottom: 1px solid var(--border-light);
    }

    .att-table tr:hover td {
        background: var(--bg-secondary);
    }

    .att-time {
        font-family: 'SF Mono', Monaco, monospace;
        font-size: 1rem;
    }

    .att-badge {
        display: inline-block;
        padding: 0.25rem 0.55rem;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .att-badge.present {
        background: rgba(34, 197, 94, 0.15);
        color: #16a34a;
    }

    .att-badge.late {
        background: rgba(245, 158, 11, 0.15);
        color: #d97706;
    }

    .att-badge.absent {
        background: rgba(239, 68, 68, 0.15);
        color: #dc2626;
    }

    .att-badge.holiday {
        background: rgba(139, 92, 246, 0.15);
        color: #7c3aed;
    }

    /* Progress Bar */
    .att-progress {
        margin-top: 1rem;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(147, 197, 253, 0.03));
        border: 1px solid rgba(59, 130, 246, 0.15);
        border-radius: 10px;
        padding: 1rem;
    }

    .att-progress-label {
        display: flex;
        justify-content: space-between;
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .att-progress-bar {
        height: 10px;
        background: var(--border-color);
        border-radius: 5px;
        overflow: hidden;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .att-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #3b82f6, #22c55e);
        border-radius: 5px;
        transition: width 0.5s ease;
        box-shadow: 0 0 8px rgba(59, 130, 246, 0.4);
    }

    /* View Toggle */
    .att-view-toggle {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .att-view-btn {
        padding: 0.65rem 1.1rem;
        border: 1.5px solid var(--border-color);
        background: var(--bg-secondary);
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .att-view-btn.active {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: #fff;
        border-color: #3b82f6;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .att-view-btn:hover:not(.active) {
        border-color: #3b82f6;
        color: #3b82f6;
        background: rgba(59, 130, 246, 0.05);
    }

    @media (max-width: 1200px) {
        .att-modal {
            max-width: 90vw;
        }
    }

    @media (max-width: 768px) {
        .att-summary {
            grid-template-columns: repeat(2, 1fr);
        }

        .att-modal {
            max-height: 95vh;
            max-width: 95vw;
        }

        .att-modal-body {
            padding: 0.75rem 1rem;
        }
    }

    /* Editable Attendance Table */
    .att-table-edit td {
        padding: 0.3rem 0.15rem;
    }

    .att-edit-time {
        width: 78px;
        padding: 0.3rem 0.35rem;
        border: 1px solid var(--border-color);
        border-radius: 5px;
        font-size: 0.95rem;
        font-family: 'SF Mono', Monaco, monospace;
        background: var(--bg-secondary);
        color: var(--text-primary);
        text-align: center;
    }

    .att-edit-time:focus {
        border-color: #3b82f6;
        outline: none;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
    }

    .att-edit-time:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .att-edit-status {
        width: 84px;
        padding: 0.3rem 0.25rem;
        border: 1px solid var(--border-color);
        border-radius: 5px;
        font-size: 0.95rem;
        background: var(--bg-secondary);
        color: var(--text-primary);
    }

    .att-edit-status:focus {
        border-color: #3b82f6;
        outline: none;
    }

    .att-edit-status:disabled {
        opacity: 0.3;
    }

    @media (max-width: 768px) {
        .ps-header {
            flex-direction: column;
            align-items: stretch;
            text-align: center;
        }

        .ps-filter {
            justify-content: center;
        }

        .ps-status-bar {
            flex-direction: column;
            text-align: center;
        }

        .ps-actions {
            justify-content: center;
        }
    }
</style>

<div class="ps-page-wrapper">

    <!-- Header -->
    <div class="ps-header fade-in-up">
        <div>
            <h1>Process Salary</h1>
            <p>Calculate monthly payroll with work hours logic</p>
            <!-- Debug: v2026-04-05-v5 -->
            <?php if ($autoSyncError): ?>
                <div style="background:#fee;color:#c00;padding:8px;border-radius:6px;font-size:12px;margin-top:6px;">
                    ⚠️ Auto-sync error: <?php echo htmlspecialchars($autoSyncError); ?>
                </div>
            <?php endif; ?>
        </div>
        <form method="GET" class="ps-filter">
            <select name="month" onchange="this.form.submit()">
                <?php foreach ($months as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" onchange="this.form.submit()">
                <?php for ($y = 2024; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <?php if (!$period): ?>
        <!-- Empty State -->
        <div class="ps-card fade-in-up">
            <div class="ps-empty">
                <div class="ps-empty-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <h3>No Payroll Period</h3>
                <p>Create a new period for <?php echo $months[$month] . ' ' . $year; ?></p>
                <form method="POST">
                    <input type="hidden" name="create_period" value="1">
                    <button type="submit" class="ps-btn ps-btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Create Period
                    </button>
                </form>
            </div>
        </div>

    <?php else: ?>

        <!-- Status Bar -->
        <div class="ps-status-bar fade-in-up" style="animation-delay: 0.1s">
            <div class="ps-status-info">
                <span class="ps-status-badge <?php echo $period['status']; ?>"><?php echo $period['status']; ?></span>
                <div>
                    <span style="font-size: 0.75rem; color: var(--text-tertiary);">Total Net Salary</span>
                    <div class="ps-total-net">Rp <?php echo number_format($period['total_net'], 0, ',', '.'); ?></div>
                </div>
            </div>

            <div class="ps-actions">
                <!-- Sync Absensi & Save always available for editing -->
                <form method="POST" style="display:inline;" title="Sync jam kerja dari data absensi GPS">
                    <input type="hidden" name="sync_attendance" value="1">
                    <button type="submit" class="ps-btn ps-btn-secondary" style="margin-right:0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="m3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        Sync Absensi
                    </button>
                </form>
                <form method="POST" id="saveProsesForm" style="display:inline;">
                    <input type="hidden" name="save_proses" value="1">
                    <button type="submit" class="ps-btn ps-btn-primary" style="margin-right:0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Save/Proses
                    </button>
                </form>
                <?php if ($period['status'] == 'draft'): ?>
                    <form method="POST" onsubmit="return confirm('Submit this payroll to Owner?')" style="display:inline;">
                        <input type="hidden" name="submit_period" value="1">
                        <button type="submit" class="ps-btn ps-btn-warning">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                            Submit to Owner
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (in_array($period['status'], ['draft', 'submitted', 'approved'])): ?>
                    <form method="POST" onsubmit="return confirm('Bayar langsung & publish slip gaji ke Staff Portal?')" style="display:inline;">
                        <input type="hidden" name="quick_pay" value="1">
                        <button type="submit" class="ps-btn ps-btn-success" style="margin-left:0.5rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                            💰 Bayar & Publish
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($period['status'] == 'submitted'): ?>
                    <form method="POST" onsubmit="return confirm('Approve and record to Cashbook?')" style="display:inline;">
                        <input type="hidden" name="approve_period" value="1">
                        <button type="submit" class="ps-btn ps-btn-success">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Approve & Record
                        </button>
                    </form>
                <?php endif; ?>

                <form method="POST" style="display: inline;">
                    <input type="hidden" name="refresh_employees" value="1">
                    <button type="submit" class="ps-btn ps-btn-refresh" title="Refresh: Add new employees to this period">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        Refresh
                    </button>
                </form>
                <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ RESET period <?php echo $months[$month] . ' ' . $year; ?>?\n\nSemua data gaji, slip, dan catatan cashbook untuk bulan ini akan DIHAPUS.\n\nAnda bisa buat ulang setelah reset.\n\nLanjutkan?');">
                    <input type="hidden" name="reset_period" value="1">
                    <button type="submit" class="ps-btn ps-btn-reset" title="Reset: Hapus period ini dan mulai ulang">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        </svg>
                        Reset
                    </button>
                </form>
                <a href="print-submission.php?period_id=<?php echo $period['id']; ?>" target="_blank" class="ps-btn ps-btn-outline">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"></polyline>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                        <rect x="6" y="14" width="12" height="8"></rect>
                    </svg>
                    Print
                </a>
            </div>
        </div>

        <!-- Info Box -->
        <?php if ($isFrozen): ?>
            <div style="background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.3); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.8rem; color: #991b1b;">
                <strong>🔒 Periode Dibekukan (Bulan Lalu):</strong> Total gaji menampilkan nilai terakhir setelah editan terakhir. Sinkronisasi otomatis & tombol Sync Absensi dinonaktifkan supaya data historis tidak berubah. Anda masih bisa mengedit angka secara manual jika perlu koreksi.
            </div>
        <?php else: ?>
            <div style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.8rem; color: #1e40af;">
                <strong>💾 Auto-Save:</strong> <strong>OT</strong> = lembur dgn approval (dibulatkan jam penuh, threshold 45 menit). <strong style="color:#b91c1c;">Extra (&gt;26hr)</strong> = hari kerja ke-27+ dlm sebulan (auto). <strong>OT Rp Harian</strong> = OT jam × (base÷200). <strong>Extra Rp</strong> = Extra jam × (base÷200). <strong>Net</strong> = Actual Base + OT Rp Harian + Extra Rp + Service + Allowance + Bonus − Deduction.
            </div>
        <?php endif; ?>

        <!-- Payroll Table -->
        <div class="ps-card fade-in-up" style="animation-delay: 0.15s">
            <div class="ps-table-container">
                <table class="ps-table">
                    <thead>
                        <tr>
                            <th style="width:30px;text-align:center;"><input type="checkbox" id="paySelectAll" onchange="togglePaySelectAll(this)" title="Pilih Semua"></th>
                            <th class="col-employee">Employee</th>
                            <th style="width: 88px;">Base<div class="ps-info">Full</div>
                            </th>
                            <th style="width: 72px; background: rgba(245,158,11,0.1); padding: 0.52rem 0.28rem; font-size: 0.74rem;">Hours<div class="ps-info" style="font-size: 0.62rem; margin-top: 2px;">200</div>
                            </th>
                            <th style="width: 98px; padding: 0.52rem 0.28rem; font-size: 0.74rem;">Actual<div class="ps-info" style="font-size: 0.62rem; margin-top: 2px;">Calc</div>
                            </th>
                            <th style="width: 54px; background: rgba(59,130,246,0.1); padding: 0.52rem 0.28rem; font-size: 0.74rem;">OT</th>
                            <th style="width: 82px; background: rgba(220,38,38,0.08); padding: 0.52rem 0.28rem; font-size: 0.72rem; color:#b91c1c;" title="Tambahan dari hari kerja melebihi 26 hari/bulan (auto, dibayar pakai rate jam-OT)">Extra<div class="ps-info" style="font-size:0.58rem;color:#b91c1c;margin-top:2px;">&gt;26hr</div>
                            </th>
                            <th style="width: 84px;" title="Uang lembur harian (OT approved) × rate jam (base÷200)">OT Rp Harian</th>
                            <th style="width: 84px;" title="Uang extra hari kerja ke-27+ × rate jam (base÷200)">Extra Rp</th>
                            <th style="width: 70px;">Service</th>
                            <th style="width: 68px;">Allowc</th>
                            <th style="width: 68px;">Bonus</th>
                            <th style="width: 74px; color: #ef4444;">Deduct</th>
                            <th style="width: 88px;">Net</th>
                            <th style="width: 55px;">Save</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slips as $slip):
                            $workHours = round((float)$slip['work_hours'], 1);
                            $baseSalary = (float)$slip['base_salary'];
                            $hourlyRate = $baseSalary / 200;
                            $actualBase = ($workHours >= 200) ? $baseSalary : round($workHours * $hourlyRate, 2);
                            $isHoursLocked = !empty($slip['hours_locked']);
                        ?>
                            <tr id="row-<?php echo $slip['id']; ?>"
                                data-loan="<?php echo $slip['deduction_loan'] ?? 0; ?>"
                                data-absence="<?php echo $slip['deduction_absence'] ?? 0; ?>"
                                data-tax="<?php echo $slip['deduction_tax'] ?? 0; ?>"
                                data-bpjs="<?php echo $slip['deduction_bpjs'] ?? 0; ?>"
                                data-other="<?php echo $slip['deduction_other'] ?? 0; ?>"
                                data-hours-locked="<?php echo $isHoursLocked ? '1' : '0'; ?>">
                                <td style="text-align:center;">
                                    <?php if (empty($slip['is_paid'])): ?>
                                        <input type="checkbox" class="pay-select-cb" value="<?php echo $slip['id']; ?>" data-net="<?php echo $slip['net_salary']; ?>" data-name="<?php echo htmlspecialchars($slip['employee_name']); ?>" onchange="updatePaySelection()">
                                    <?php else: ?>
                                        <span title="Sudah dibayar" style="color:#10b981;font-size:14px;">✅</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-employee">
                                    <div class="ps-emp-row">
                                        <div class="ps-emp-info">
                                            <div class="ps-emp-name"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                                            <div class="ps-emp-pos"><?php echo htmlspecialchars($slip['position']); ?></div>
                                        </div>
                                        <button type="button" class="ps-btn-attendance" title="Lihat Detail Absensi"
                                            onclick="showAttendanceDetail(<?php echo $slip['employee_id']; ?>, '<?php echo htmlspecialchars(addslashes($slip['employee_name'])); ?>')">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                            </svg>
                                        </button>
                                    </div>
                                </td>

                                <td>
                                    <input type="text" class="ps-input currency-input"
                                        value="<?php echo number_format((float)$slip['base_salary'], 0, ',', '.'); ?>"
                                        data-field="base_salary" data-id="<?php echo $slip['id']; ?>"
                                        readonly
                                        title="Gaji pokok hanya bisa diedit dari menu Employee Data"
                                        style="background:#f1f5f9;cursor:not-allowed;">
                                </td>

                                <td>
                                    <div style="display:flex;align-items:center;gap:2px;">
                                        <input type="number" class="ps-input highlight-hours"
                                            value="<?php echo $workHours; ?>" step="0.5" min="0" max="300"
                                            data-field="work_hours" data-id="<?php echo $slip['id']; ?>"
                                            oninput="calculateRow(<?php echo $slip['id']; ?>); saveRow(<?php echo $slip['id']; ?>)"
                                            title="<?php echo $isHoursLocked ? 'Manual (dikunci)' : 'Auto dari absensi'; ?>">
                                        <?php if ($isHoursLocked): ?>
                                            <button type="button" onclick="unlockHours(<?php echo $slip['id']; ?>)"
                                                title="Reset ke data absensi"
                                                style="border:none;background:none;cursor:pointer;padding:0;color:#f59e0b;font-size:11px;line-height:1;">🔒</button>
                                        <?php else: ?>
                                            <span style="font-size:9px;color:var(--text-tertiary);opacity:0.6;" title="Auto-sync dari absensi">🔄</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <span id="actual-base-<?php echo $slip['id']; ?>" class="ps-cell-calc">
                                        <?php echo number_format($actualBase, 0, ',', '.'); ?>
                                    </span>
                                </td>

                                <td>
                                    <input type="number" class="ps-input" style="background: rgba(59,130,246,0.1);"
                                        value="<?php echo $slip['overtime_hours']; ?>" step="1" min="0"
                                        data-field="overtime_hours" data-id="<?php echo $slip['id']; ?>"
                                        title="OT yang disetujui / dimasukkan manual (jam penuh, kelipatan 1 jam dgn threshold 45 menit)"
                                        oninput="calculateRow(<?php echo $slip['id']; ?>); saveRow(<?php echo $slip['id']; ?>)">
                                </td>

                                <td style="text-align:center;background:rgba(220,38,38,0.04);">
                                    <?php
                                    $extraInfo = $extraMap[(int)$slip['employee_id']] ?? ['hours' => 0, 'days' => 0];
                                    $autoExtraJ = (float)$extraInfo['hours'];
                                    $extraD     = (int)$extraInfo['days'];
                                    $extraH     = (float)($slip['extra_hours'] ?? 0);
                                    $extraLocked = !empty($slip['extra_locked']);
                                    $extraRp    = (float)($slip['extra_amount'] ?? 0);
                                    ?>
                                    <div style="display:flex;align-items:center;gap:2px;justify-content:center;">
                                        <input type="number" class="ps-input"
                                            value="<?php echo (float)$extraH; ?>" step="1" min="0"
                                            data-field="extra_hours" data-id="<?php echo $slip['id']; ?>"
                                            style="background:rgba(220,38,38,0.08);color:#b91c1c;font-weight:700;text-align:center;width:52px;"
                                            title="Extra Hari (jam dari hari kerja ke-27+). Auto dari absensi: <?php echo $autoExtraJ; ?>j (<?php echo $extraD; ?> hari). Edit untuk override."
                                            oninput="calculateRow(<?php echo $slip['id']; ?>); saveRow(<?php echo $slip['id']; ?>)">
                                        <?php if ($extraLocked): ?>
                                            <button type="button" onclick="unlockExtra(<?php echo $slip['id']; ?>, <?php echo $autoExtraJ; ?>)"
                                                title="Reset ke auto dari absensi (<?php echo $autoExtraJ; ?>j)"
                                                style="border:none;background:none;cursor:pointer;padding:0;color:#f59e0b;font-size:11px;line-height:1;">🔒</button>
                                        <?php else: ?>
                                            <span style="font-size:9px;color:var(--text-tertiary);opacity:0.6;" title="Auto dari absensi (<?php echo $autoExtraJ; ?>j)">🔄</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <span id="ot-amount-<?php echo $slip['id']; ?>" class="ps-cell-calc"
                                        data-ot-base="<?php echo (float)$slip['overtime_amount']; ?>"
                                        title="Uang lembur harian (OT approved)">
                                        <?php echo number_format((float)$slip['overtime_amount'], 0, ',', '.'); ?>
                                    </span>
                                </td>

                                <td style="text-align:center;background:rgba(220,38,38,0.04);">
                                    <span id="extra-amount-<?php echo $slip['id']; ?>" class="ps-cell-calc"
                                        data-extra-amount="<?php echo $extraRp; ?>"
                                        title="Uang extra hari kerja ke-27+">
                                        <?php echo number_format($extraRp, 0, ',', '.'); ?>
                                    </span>
                                </td>

                                <td>
                                    <input type="text" class="ps-input currency-input"
                                        value="<?php echo number_format($slip['incentive'], 0, ',', '.'); ?>"
                                        data-field="incentive" data-id="<?php echo $slip['id']; ?>"
                                        oninput="calculateRow(<?php echo $slip['id']; ?>); saveRow(<?php echo $slip['id']; ?>)">
                                </td>

                                <td>
                                    <input type="text" class="ps-input currency-input"
                                        value="<?php echo number_format($slip['allowance'], 0, ',', '.'); ?>"
                                        data-field="allowance" data-id="<?php echo $slip['id']; ?>"
                                        oninput="calculateRow(<?php echo $slip['id']; ?>); saveRow(<?php echo $slip['id']; ?>)">
                                </td>

                                <td>
                                    <!-- Uang Makan dihapus dari UI; hidden 0 agar JS save tetap kirim field -->
                                    <input type="hidden" data-field="uang_makan" data-id="<?php echo $slip['id']; ?>" value="0">
                                    <input type="text" class="ps-input currency-input"
                                        value="<?php echo number_format($slip['bonus'] + $slip['other_income'], 0, ',', '.'); ?>"
                                        data-field="bonus" data-id="<?php echo $slip['id']; ?>"
                                        oninput="calculateRow(<?php echo $slip['id']; ?>); saveRow(<?php echo $slip['id']; ?>)">
                                </td>

                                <td>
                                    <input type="text" class="ps-input currency-input negative"
                                        value="<?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?>"
                                        data-field="total_deductions" data-id="<?php echo $slip['id']; ?>"
                                        readonly onclick="openDeductionModal(<?php echo $slip['id']; ?>, '<?php echo htmlspecialchars(addslashes($slip['employee_name'])); ?>')"
                                        style="cursor: pointer;" title="Click to edit deductions">
                                </td>

                                <td style="position: relative;">
                                    <span id="net-<?php echo $slip['id']; ?>" class="ps-cell-net">
                                        <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?>
                                    </span>
                                    <span id="save-indicator-<?php echo $slip['id']; ?>" class="save-indicator"></span>
                                </td>

                                <td style="text-align:center;">
                                    <button type="button" class="ps-btn-save-row" id="save-btn-<?php echo $slip['id']; ?>" title="Simpan baris ini"
                                        onclick="saveRow(<?php echo $slip['id']; ?>)" style="display:none;background:#10b981;color:#fff;border:none;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:0.72rem;font-weight:600;white-space:nowrap;">
                                        💾
                                    </button>
                                    <span id="saved-label-<?php echo $slip['id']; ?>" style="color:#6b7280;font-size:0.65rem;">—</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Deduction Modal -->
<div class="ps-modal-overlay" id="deductionModal">
    <div class="ps-modal">
        <div class="ps-modal-header">
            <h4 class="ps-modal-title">Edit Deductions: <span id="modalEmpName"></span></h4>
            <button type="button" class="ps-modal-close" onclick="closeDeductionModal()">&times;</button>
        </div>
        <input type="hidden" id="modalSlipId">

        <div class="ps-modal-grid">
            <div class="ps-form-group">
                <label class="ps-form-label">Loan / Cash Advance</label>
                <input type="text" class="ps-form-input currency-input" id="modalLoan" placeholder="0">
            </div>
            <div class="ps-form-group">
                <label class="ps-form-label">Absence Deduction</label>
                <input type="text" class="ps-form-input currency-input" id="modalAbsence" placeholder="0">
            </div>
            <div class="ps-form-group">
                <label class="ps-form-label">Tax (PPh)</label>
                <input type="text" class="ps-form-input currency-input" id="modalTax" placeholder="0">
            </div>
            <div class="ps-form-group">
                <label class="ps-form-label">BPJS</label>
                <input type="text" class="ps-form-input currency-input" id="modalBpjs" placeholder="0">
            </div>
            <div class="ps-form-group" style="grid-column: span 2;">
                <label class="ps-form-label">Other Deductions</label>
                <input type="text" class="ps-form-input currency-input" id="modalOther" placeholder="0">
            </div>
        </div>

        <div class="ps-modal-total">
            <span>Total Deductions:</span>
            <span id="modalTotalDed" class="ps-modal-total-amount">Rp 0</span>
        </div>

        <div class="ps-modal-actions">
            <button type="button" class="ps-btn ps-btn-outline" onclick="closeDeductionModal()">Cancel</button>
            <button type="button" class="ps-btn ps-btn-primary" onclick="saveDeduction()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                Save
            </button>
        </div>
    </div>
</div>

<!-- Attendance Detail Modal -->
<div class="att-modal-overlay" id="attendanceModal">
    <div class="att-modal">
        <div class="att-modal-header">
            <h4 class="att-modal-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                Detail Absensi: <span id="attEmpName"></span>
            </h4>
            <button type="button" class="ps-modal-close" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <div class="att-modal-body" id="attModalBody">
            <div style="text-align: center; padding: 2rem;">
                <div style="border: 3px solid var(--border-color); border-top-color: #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--text-tertiary);">Loading attendance data...</p>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<script>
    // Format Currency Input on keyup
    document.querySelectorAll('.currency-input').forEach(input => {
        input.addEventListener('keyup', function(e) {
            let val = this.value.replace(/\D/g, '');
            this.value = new Intl.NumberFormat('id-ID').format(val);
        });
    });

    // Intercept Save/Proses form — flush all pending saves first
    document.getElementById('saveProsesForm')?.addEventListener('submit', function(e) {
        // Check if there are any pending saves (debounced or in-flight)
        const hasPending = Object.keys(_saveTimers).length > 0 || Object.keys(_savePromises).length > 0;
        if (hasPending) {
            e.preventDefault();
            const form = this;
            flushAllPendingSaves().then(() => {
                form.submit();
            }).catch(() => {
                form.submit();
            });
        }
    });

    // Global: flush pending saves before ANY other form submission (quick_pay, approve, mark_paid, etc.)
    document.addEventListener('submit', function(e) {
        // Skip forms already handled individually
        if (e.target.id === 'saveProsesForm' || e.target.id === 'paySelForm') return;
        const hasPending = Object.keys(_saveTimers).length > 0 || Object.keys(_savePromises).length > 0;
        if (hasPending) {
            e.preventDefault();
            const form = e.target;
            flushAllPendingSaves().then(() => form.submit()).catch(() => form.submit());
        }
    });

    function getValByRow(id, field) {
        let el = document.querySelector(`input[data-id="${id}"][data-field="${field}"]`);
        if (!el) return 0;
        if (field === 'overtime_hours' || field === 'work_hours' || field === 'extra_hours') return parseFloat(el.value) || 0;
        return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
    }

    function calculateRow(id) {
        let base = getValByRow(id, 'base_salary');
        let workHours = getValByRow(id, 'work_hours');
        let otHours = getValByRow(id, 'overtime_hours');

        // Hourly rate = Base / 200
        let hourlyRate = base / 200;

        // Gaji pokok PENUH (gaji bulanan tetap) — tidak diprorata jam kerja.
        let actualBase = base;

        // Update Actual Base Display
        document.getElementById(`actual-base-${id}`).innerText = new Intl.NumberFormat('id-ID').format(actualBase);

        // Overtime Amount = OT approved + Extra Hari (>26hr), keduanya pakai rate yg sama
        let otAmount = Math.round(otHours * hourlyRate);
        let extraHours = getValByRow(id, 'extra_hours');
        let extraAmount = Math.round(extraHours * hourlyRate);
        let otCell = document.getElementById(`ot-amount-${id}`);
        if (otCell) otCell.innerText = new Intl.NumberFormat('id-ID').format(otAmount);
        let extraCell = document.getElementById(`extra-amount-${id}`);
        if (extraCell) extraCell.innerText = new Intl.NumberFormat('id-ID').format(extraAmount);

        // Other incomes
        let incentive = getValByRow(id, 'incentive');
        let allowance = getValByRow(id, 'allowance');
        // uang_makan dihilangkan dari Net
        let bonus = getValByRow(id, 'bonus'); // combined bonus+other

        // Deductions
        let row = document.getElementById(`row-${id}`);
        let loan = parseFloat(row.getAttribute('data-loan')) || 0;
        let absence = parseFloat(row.getAttribute('data-absence')) || 0;
        let tax = parseFloat(row.getAttribute('data-tax')) || 0;
        let bpjs = parseFloat(row.getAttribute('data-bpjs')) || 0;
        let dedOther = parseFloat(row.getAttribute('data-other')) || 0;
        let totalDed = loan + absence + tax + bpjs + dedOther;

        // Update deductions input display
        let dedInput = document.querySelector(`input[data-id="${id}"][data-field="total_deductions"]`);
        if (dedInput) dedInput.value = new Intl.NumberFormat('id-ID').format(totalDed);

        // Calculate Net (uang_makan dihilangkan; OT total sudah termasuk Extra Hari)
        let totalEarn = actualBase + otAmount + extraAmount + incentive + allowance + bonus;
        let net = totalEarn - totalDed;
        document.getElementById(`net-${id}`).innerText = new Intl.NumberFormat('id-ID').format(net);

        // Show save button (user must click to save)
        let saveBtn = document.getElementById(`save-btn-${id}`);
        let savedLabel = document.getElementById(`saved-label-${id}`);
        if (saveBtn) {
            saveBtn.style.display = 'inline-block';
        }
        if (savedLabel) {
            savedLabel.style.display = 'none';
        }
    }

    function showSaveIndicator(id) {
        let indicator = document.getElementById(`save-indicator-${id}`);
        if (indicator) {
            indicator.classList.add('saving');
            indicator.innerHTML = '<span class="save-dot"></span> Saving...';
        }
    }

    // Debounce timers per row to prevent double-save
    const _saveTimers = {};
    const _savePromises = {}; // Track in-flight AJAX save promises

    function saveRow(id) {
        // Debounce: wait 800ms after last input to actually save
        if (_saveTimers[id]) clearTimeout(_saveTimers[id]);
        _saveTimers[id] = setTimeout(() => _doSaveRow(id), 800);
    }

    // Flush ALL pending saves (debounced + in-flight) — returns Promise
    function flushAllPendingSaves() {
        const promises = [];
        // Fire all pending debounce timers immediately
        for (const id in _saveTimers) {
            if (_saveTimers[id]) {
                clearTimeout(_saveTimers[id]);
                const p = _doSaveRow(id);
                if (p) promises.push(p);
            }
        }
        // Wait for any in-flight saves too
        for (const id in _savePromises) {
            if (_savePromises[id]) promises.push(_savePromises[id]);
        }
        return promises.length > 0 ? Promise.all(promises) : Promise.resolve();
    }

    function _doSaveRow(id) {
        delete _saveTimers[id];
        const row = document.getElementById(`row-${id}`);
        if (!row) return Promise.resolve();

        const data = new FormData();
        data.append('ajax_update', 1);
        data.append('slip_id', id);

        data.append('base_salary', getValByRow(id, 'base_salary'));
        data.append('work_hours', getValByRow(id, 'work_hours'));
        data.append('overtime_hours', getValByRow(id, 'overtime_hours'));
        data.append('extra_hours', getValByRow(id, 'extra_hours'));
        data.append('incentive', getValByRow(id, 'incentive'));
        data.append('allowance', getValByRow(id, 'allowance'));
        data.append('uang_makan', getValByRow(id, 'uang_makan'));
        data.append('bonus', getValByRow(id, 'bonus'));
        data.append('other_income', 0);

        data.append('deduction_loan', row.getAttribute('data-loan') || 0);
        data.append('deduction_absence', row.getAttribute('data-absence') || 0);
        data.append('deduction_tax', row.getAttribute('data-tax') || 0);
        data.append('deduction_bpjs', row.getAttribute('data-bpjs') || 0);
        data.append('deduction_other', row.getAttribute('data-other') || 0);

        // Show saving state on button
        let saveBtn = document.getElementById(`save-btn-${id}`);
        let savedLabel = document.getElementById(`saved-label-${id}`);
        if (saveBtn) {
            saveBtn.textContent = '⏳';
            saveBtn.disabled = true;
        }

        const promise = fetch('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            }).then(res => {
                if (!res.ok) {
                    return res.text().then(t => {
                        throw new Error('HTTP ' + res.status + ': ' + t.substring(0, 200));
                    });
                }
                return res.json();
            })
            .then(res => {
                if (res.status === 'success') {
                    // Hide save button, show saved label
                    if (saveBtn) {
                        saveBtn.style.display = 'none';
                        saveBtn.textContent = '💾';
                        saveBtn.disabled = false;
                    }
                    if (savedLabel) {
                        savedLabel.style.display = 'inline';
                        savedLabel.textContent = '✅';
                        savedLabel.style.color = '#10b981';
                    }
                    setTimeout(() => {
                        if (savedLabel) {
                            savedLabel.textContent = '—';
                            savedLabel.style.color = '#6b7280';
                        }
                    }, 3000);
                    // Mark as hours_locked and swap icon to 🔒
                    if (res.hours_locked) {
                        const row = document.getElementById(`row-${id}`);
                        if (row) row.setAttribute('data-hours-locked', '1');
                        const hoursInput = document.querySelector(`input[data-id="${id}"][data-field="work_hours"]`);
                        if (hoursInput) {
                            hoursInput.title = 'Manual (dikunci)';
                            const syncIcon = hoursInput.nextElementSibling;
                            if (syncIcon && syncIcon.tagName === 'SPAN') {
                                syncIcon.outerHTML = `<button type="button" onclick="unlockHours(${id})" title="Reset ke data absensi" style="border:none;background:none;cursor:pointer;padding:0;color:#f59e0b;font-size:11px;line-height:1;">🔒</button>`;
                            }
                        }
                    }
                    // Update totals in header
                    updateTotals();
                } else {
                    console.error('Save failed for slip', id, res);
                    if (saveBtn) {
                        saveBtn.textContent = '❌';
                        saveBtn.disabled = false;
                    }
                    if (savedLabel) {
                        savedLabel.style.display = 'inline';
                        savedLabel.textContent = '❌ ' + (res.message || 'Error');
                        savedLabel.style.color = '#ef4444';
                    }
                }
            }).catch(err => {
                console.error('Save error for slip', id, err);
                if (saveBtn) {
                    saveBtn.textContent = '❌';
                    saveBtn.disabled = false;
                }
                if (savedLabel) {
                    savedLabel.style.display = 'inline';
                    savedLabel.textContent = '❌ Network error';
                    savedLabel.style.color = '#ef4444';
                }
            }).finally(() => {
                delete _savePromises[id];
            });

        _savePromises[id] = promise;
        return promise;
    }

    // Same as saveRow but always returns a Promise (for flushing before form submit)
    function saveRowSync(id) {
        if (_saveTimers[id]) {
            clearTimeout(_saveTimers[id]);
            return _doSaveRow(id) || Promise.resolve();
        }
        return _savePromises[id] || Promise.resolve();
    }

    // ── Flush all pending saves on page unload (browser refresh/close) ──
    window.addEventListener('beforeunload', function(e) {
        // Find all rows with pending debounce timers
        for (const id in _saveTimers) {
            if (_saveTimers[id]) {
                clearTimeout(_saveTimers[id]);
                delete _saveTimers[id];
                // Build form data for this row
                const row = document.getElementById(`row-${id}`);
                if (!row) continue;
                const params = new URLSearchParams();
                params.append('ajax_update', 1);
                params.append('slip_id', id);
                params.append('base_salary', getValByRow(id, 'base_salary'));
                params.append('work_hours', getValByRow(id, 'work_hours'));
                params.append('overtime_hours', getValByRow(id, 'overtime_hours'));
                params.append('incentive', getValByRow(id, 'incentive'));
                params.append('allowance', getValByRow(id, 'allowance'));
                params.append('uang_makan', getValByRow(id, 'uang_makan'));
                params.append('bonus', getValByRow(id, 'bonus'));
                params.append('other_income', 0);
                params.append('deduction_loan', row.getAttribute('data-loan') || 0);
                params.append('deduction_absence', row.getAttribute('data-absence') || 0);
                params.append('deduction_tax', row.getAttribute('data-tax') || 0);
                params.append('deduction_bpjs', row.getAttribute('data-bpjs') || 0);
                params.append('deduction_other', row.getAttribute('data-other') || 0);
                // sendBeacon survives page navigation
                navigator.sendBeacon('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', params);
            }
        }
    });

    function updateTotals() {
        let totalNet = 0;
        document.querySelectorAll('[id^="net-"]').forEach(el => {
            totalNet += parseFloat(el.innerText.replace(/\./g, '').replace(/,/g, '')) || 0;
        });
        let totalDisplay = document.querySelector('.ps-total-net');
        if (totalDisplay) {
            totalDisplay.innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(totalNet);
        }
    }

    // Modal Functions
    function openDeductionModal(id, name) {
        document.getElementById('modalSlipId').value = id;
        document.getElementById('modalEmpName').innerText = name;

        const row = document.getElementById(`row-${id}`);
        document.getElementById('modalLoan').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-loan') || 0);
        document.getElementById('modalAbsence').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-absence') || 0);
        document.getElementById('modalTax').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-tax') || 0);
        document.getElementById('modalBpjs').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-bpjs') || 0);
        document.getElementById('modalOther').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-other') || 0);

        updateModalTotal();
        document.getElementById('deductionModal').classList.add('active');

        // Focus first input
        setTimeout(() => document.getElementById('modalLoan').focus(), 100);
    }

    function closeDeductionModal() {
        document.getElementById('deductionModal').classList.remove('active');
    }

    function getVal(selector) {
        let el = document.querySelector(selector);
        if (!el) return 0;
        return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
    }

    function updateModalTotal() {
        let loan = getVal('#modalLoan');
        let abs = getVal('#modalAbsence');
        let tax = getVal('#modalTax');
        let bpjs = getVal('#modalBpjs');
        let other = getVal('#modalOther');
        let total = loan + abs + tax + bpjs + other;
        document.getElementById('modalTotalDed').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
    }

    // Add event listeners for real-time modal total
    ['modalLoan', 'modalAbsence', 'modalTax', 'modalBpjs', 'modalOther'].forEach(id => {
        document.getElementById(id)?.addEventListener('keyup', updateModalTotal);
    });

    function saveDeduction() {
        let id = document.getElementById('modalSlipId').value;
        let loan = getVal('#modalLoan');
        let abs = getVal('#modalAbsence');
        let tax = getVal('#modalTax');
        let bpjs = getVal('#modalBpjs');
        let other = getVal('#modalOther');

        let row = document.getElementById(`row-${id}`);
        row.setAttribute('data-loan', loan);
        row.setAttribute('data-absence', abs);
        row.setAttribute('data-tax', tax);
        row.setAttribute('data-bpjs', bpjs);
        row.setAttribute('data-other', other);

        closeDeductionModal();
        calculateRow(id);
        // Auto-save after editing deductions
        saveRow(id);
    }

    // Close modal on backdrop click
    document.getElementById('deductionModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDeductionModal();
    });

    // ── Unlock Hours (reset to attendance auto-sync) ──
    function unlockHours(id) {
        if (!confirm('Reset jam kerja ke data absensi otomatis? Perubahan manual akan hilang.')) return;
        const data = new FormData();
        data.append('ajax_unlock_hours', 1);
        data.append('slip_id', id);
        fetch('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', {
                method: 'POST',
                body: data
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    // Update hours input and remove lock icon
                    const input = document.querySelector(`input[data-id="${id}"][data-field="work_hours"]`);
                    if (input) {
                        input.value = res.work_hours;
                        input.title = 'Auto dari absensi';
                    }
                    const row = document.getElementById(`row-${id}`);
                    if (row) row.setAttribute('data-hours-locked', '0');
                    // Replace lock icon with sync icon
                    const lockBtn = input?.nextElementSibling;
                    if (lockBtn && lockBtn.tagName === 'BUTTON') {
                        lockBtn.outerHTML = '<span style="font-size:9px;color:var(--text-tertiary);opacity:0.6;" title="Auto-sync dari absensi">🔄</span>';
                    }
                    calculateRow(id);
                }
            });
    }

    // ── Unlock Extra Hours (reset to attendance auto) ──
    function unlockExtra(id, autoVal) {
        if (!confirm('Reset jam Extra (>26hr) ke data absensi otomatis? Override manual akan hilang.')) return;
        const data = new FormData();
        data.append('ajax_unlock_extra', 1);
        data.append('slip_id', id);
        fetch('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', {
                method: 'POST',
                body: data
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    const input = document.querySelector(`input[data-id="${id}"][data-field="extra_hours"]`);
                    if (input) input.value = res.extra_hours;
                    const lockBtn = input?.nextElementSibling;
                    if (lockBtn && lockBtn.tagName === 'BUTTON') {
                        lockBtn.outerHTML = '<span style="font-size:9px;color:var(--text-tertiary);opacity:0.6;" title="Auto dari absensi">🔄</span>';
                    }
                    calculateRow(id);
                } else {
                    alert('Gagal reset: ' + (res.message || 'unknown'));
                }
            });
    }

    // === Pay Selection Functions ===
    function togglePaySelectAll(master) {
        document.querySelectorAll('.pay-select-cb').forEach(cb => {
            cb.checked = master.checked;
        });
        updatePaySelection();
    }

    function updatePaySelection() {
        const checked = document.querySelectorAll('.pay-select-cb:checked');
        const bar = document.getElementById('paySelectionBar');
        if (!bar) return;
        if (checked.length === 0) {
            bar.style.display = 'none';
            return;
        }
        let total = 0;
        checked.forEach(cb => {
            total += parseFloat(cb.getAttribute('data-net') || 0);
        });
        document.getElementById('paySelCount').textContent = checked.length;
        document.getElementById('paySelTotal').textContent = new Intl.NumberFormat('id-ID').format(total);
        bar.style.display = 'flex';
    }

    function paySelected() {
        const checked = document.querySelectorAll('.pay-select-cb:checked');
        if (checked.length === 0) return;
        let names = [];
        checked.forEach(cb => names.push(cb.getAttribute('data-name')));
        const preview = names.length <= 3 ? names.join(', ') : names.slice(0, 3).join(', ') + ' +' + (names.length - 3) + ' lainnya';
        if (!confirm('Bayar & publish slip gaji untuk ' + checked.length + ' staff?\n' + preview)) return;
        const ids = Array.from(checked).map(cb => cb.value).join(',');
        document.getElementById('paySelSlipIds').value = ids;

        // Flush ALL pending saves before submitting pay form
        flushAllPendingSaves().then(() => {
            document.getElementById('paySelForm').submit();
        }).catch(() => {
            document.getElementById('paySelForm').submit();
        });
    }

    // ═══ Attendance Detail Functions ═══
    let currentAttView = 'table'; // default to table for editing
    let currentAttEmpId = null;
    let currentAttMonth = null;
    let currentAttYear = null;

    function showAttendanceDetail(empId, empName) {
        currentAttEmpId = empId;
        document.getElementById('attEmpName').innerText = empName;
        document.getElementById('attendanceModal').classList.add('active');

        const month = <?php echo $month; ?>;
        const year = <?php echo $year; ?>;
        currentAttMonth = month;
        currentAttYear = year;

        fetch(`process.php?ajax_attendance=1&emp_id=${empId}&m=${month}&y=${year}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderAttendanceDetail(data);
                } else {
                    document.getElementById('attModalBody').innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: #ef4444;">
                        <p>Error: ${data.error || 'Gagal memuat data'}</p>
                    </div>
                `;
                }
            })
            .catch(err => {
                document.getElementById('attModalBody').innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #ef4444;">
                    <p>Network error</p>
                </div>
            `;
            });
    }

    function renderAttendanceDetail(data) {
        const s = data.summary;
        const progressPct = Math.min((s.total_hours / s.target_hours) * 100, 100);
        currentAttEmpId = data.employee_id || currentAttEmpId;

        // Generate calendar HTML
        let calendarHtml = '';
        const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        dayNames.forEach(d => calendarHtml += `<div class="att-cal-header">${d}</div>`);

        const firstDayStr = data.calendar[0]?.date;
        if (firstDayStr) {
            const firstDayOfWeek = new Date(firstDayStr).getDay();
            const offset = firstDayOfWeek === 0 ? 6 : firstDayOfWeek - 1;
            for (let i = 0; i < offset; i++) {
                calendarHtml += '<div class="att-cal-day empty"></div>';
            }
        }

        data.calendar.forEach(day => {
            const att = day.attendance;
            let statusClass = '';
            let hoursText = '';
            if (att) {
                statusClass = att.effective_status || att.status || 'present';
                if (att.work_hours) hoursText = `${parseFloat(att.work_hours).toFixed(1)}h`;
            } else if (day.is_weekend) {
                statusClass = 'weekend';
            }
            calendarHtml += `
            <div class="att-cal-day ${statusClass}" title="${day.date}">
                <span class="att-cal-date">${day.day}</span>
                ${hoursText ? `<span class="att-cal-hours">${hoursText}</span>` : ''}
                ${att ? '<span class="att-cal-status"></span>' : ''}
            </div>
        `;
        });

        // Generate EDITABLE table HTML — show ALL days
        let tableHtml = `
        <table class="att-table att-table-edit">
            <thead>
                <tr>
                    <th style="width:75px;">Tgl</th>
                    <th style="width:70px;">Scan 1</th>
                    <th style="width:70px;">Scan 2</th>
                    <th style="width:70px;">Scan 3</th>
                    <th style="width:70px;">Scan 4</th>
                    <th style="width:55px;">S1</th>
                    <th style="width:55px;">S2</th>
                    <th style="width:55px;">Total</th>
                    <th style="width:80px;">Status</th>
                </tr>
            </thead>
            <tbody>
    `;

        const today = new Date().toISOString().slice(0, 10);
        data.calendar.forEach(day => {
            const att = day.attendance;
            const d = day.date;
            const isFuture = d > today;
            const ci = att?.check_in_time ? att.check_in_time.substring(0, 5) : '';
            const co = att?.check_out_time ? att.check_out_time.substring(0, 5) : '';
            const s3 = att?.scan_3 ? att.scan_3.substring(0, 5) : '';
            const s4 = att?.scan_4 ? att.scan_4.substring(0, 5) : '';
            const sh1 = att?.shift_1_hours ? parseFloat(att.shift_1_hours).toFixed(1) : '0.0';
            const sh2 = att?.shift_2_hours ? parseFloat(att.shift_2_hours).toFixed(1) : '0.0';
            const tot = att?.work_hours ? parseFloat(att.work_hours).toFixed(1) : '0.0';
            const sts = att?.effective_status || att?.status || (day.is_weekend ? 'holiday' : '');
            const rowClass = isFuture ? 'opacity:0.4;' : (att ? '' : (day.is_weekend ? 'opacity:0.5;' : ''));
            const dayLabel = day.day + ' ' + day.day_name;

            tableHtml += `
            <tr data-date="${d}" style="${rowClass}">
                <td style="font-weight:600;font-size:0.72rem;white-space:nowrap;">${dayLabel}</td>
                <td><input type="time" class="att-edit-time" value="${ci}" data-col="check_in" ${isFuture?'disabled':''}></td>
                <td><input type="time" class="att-edit-time" value="${co}" data-col="check_out" ${isFuture?'disabled':''}></td>
                <td><input type="time" class="att-edit-time" value="${s3}" data-col="scan_3" ${isFuture?'disabled':''}></td>
                <td><input type="time" class="att-edit-time" value="${s4}" data-col="scan_4" ${isFuture?'disabled':''}></td>
                <td class="att-calc-sh1" style="font-size:0.7rem;color:var(--text-tertiary);">${sh1}</td>
                <td class="att-calc-sh2" style="font-size:0.7rem;color:var(--text-tertiary);">${sh2}</td>
                <td class="att-calc-total" style="font-weight:700;font-size:0.75rem;">${tot}</td>
                <td>
                    <select class="att-edit-status" data-col="status" ${isFuture?'disabled':''}>
                        <option value="">—</option>
                        <option value="present" ${sts==='present'?'selected':''}>Hadir</option>
                        <option value="late" ${sts==='late'?'selected':''}>Telat</option>
                        <option value="absent" ${sts==='absent'?'selected':''}>Absen</option>
                        <option value="leave" ${sts==='leave'?'selected':''}>Cuti</option>
                        <option value="holiday" ${sts==='holiday'?'selected':''}>Libur</option>
                        <option value="half_day" ${sts==='half_day'?'selected':''}>½ Hari</option>
                    </select>
                </td>
            </tr>
        `;
        });
        tableHtml += '</tbody></table>';

        document.getElementById('attModalBody').innerHTML = `
        <!-- Summary Cards -->
        <div class="att-summary">
            <div class="att-summary-card primary">
                <div class="att-summary-value">${s.total_days}</div>
                <div class="att-summary-label">Hari Hadir</div>
            </div>
            <div class="att-summary-card success">
                <div class="att-summary-value" id="attTotalHours">${s.total_hours}</div>
                <div class="att-summary-label">Total Jam</div>
            </div>
            <div class="att-summary-card warning">
                <div class="att-summary-value">${s.late_count}</div>
                <div class="att-summary-label">Terlambat</div>
            </div>
            <div class="att-summary-card danger">
                <div class="att-summary-value">${s.absent_count}</div>
                <div class="att-summary-label">Tidak Hadir</div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="att-progress">
            <div class="att-progress-label">
                <span>Progress Jam Kerja</span>
                <span><strong id="attProgressHours">${s.total_hours}</strong> / ${s.target_hours} jam (<span id="attProgressPct">${progressPct.toFixed(0)}</span>%)</span>
            </div>
            <div class="att-progress-bar">
                <div class="att-progress-fill" id="attProgressBar" style="width: ${progressPct}%"></div>
            </div>
        </div>

        <!-- View Toggle -->
        <div class="att-view-toggle">
            <button class="att-view-btn ${currentAttView === 'calendar' ? 'active' : ''}" onclick="toggleAttView('calendar')">📅 Kalender</button>
            <button class="att-view-btn ${currentAttView === 'table' ? 'active' : ''}" onclick="toggleAttView('table')">✏️ Edit Harian</button>
            <button class="att-view-btn" onclick="printAttendanceDetail()">🖨️ Print</button>
        </div>

        <!-- Calendar View -->
        <div id="attCalendarView" style="${currentAttView === 'calendar' ? '' : 'display:none'}">
            <div class="att-calendar">${calendarHtml}</div>
        </div>

        <!-- Table View (Editable) -->
        <div id="attTableView" style="${currentAttView === 'table' ? '' : 'display:none'}">
            ${tableHtml}
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:0.75rem;">
                <span id="attSaveStatus" style="font-size:0.75rem;color:var(--text-tertiary);align-self:center;"></span>
                <button type="button" class="ps-btn ps-btn-primary" onclick="saveAllDailyAttendance()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Simpan Semua
                </button>
            </div>
        </div>
    `;

        // Attach auto-calc listeners to time inputs
        document.querySelectorAll('.att-edit-time').forEach(input => {
            input.addEventListener('change', function() {
                recalcAttRow(this.closest('tr'));
            });
        });
    }

    function closeAttendanceModal() {
        document.getElementById('attendanceModal').classList.remove('active');
    }

    function printAttendanceDetail() {
        if (!currentAttEmpId) return;
        const m = currentAttMonth || <?php echo $month; ?>;
        const y = currentAttYear || <?php echo $year; ?>;
        window.open(`print-attendance.php?emp_id=${currentAttEmpId}&m=${m}&y=${y}`, '_blank');
    }

    function toggleAttView(view) {
        currentAttView = view;
        document.getElementById('attCalendarView').style.display = view === 'calendar' ? '' : 'none';
        document.getElementById('attTableView').style.display = view === 'table' ? '' : 'none';
        document.querySelectorAll('.att-view-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
    }

    function recalcAttRow(tr) {
        if (!tr) return;
        const ci = tr.querySelector('[data-col="check_in"]')?.value || '';
        const co = tr.querySelector('[data-col="check_out"]')?.value || '';
        const s3 = tr.querySelector('[data-col="scan_3"]')?.value || '';
        const s4 = tr.querySelector('[data-col="scan_4"]')?.value || '';

        let sh1 = 0,
            sh2 = 0;
        if (ci && co) {
            const [h1, m1] = ci.split(':').map(Number);
            const [h2, m2] = co.split(':').map(Number);
            sh1 = Math.max(0, (h2 * 60 + m2 - h1 * 60 - m1) / 60);
        }
        if (s3 && s4) {
            const [h3, m3] = s3.split(':').map(Number);
            const [h4, m4] = s4.split(':').map(Number);
            sh2 = Math.max(0, (h4 * 60 + m4 - h3 * 60 - m3) / 60);
        }
        const total = sh1 + sh2;
        tr.querySelector('.att-calc-sh1').textContent = sh1.toFixed(1);
        tr.querySelector('.att-calc-sh2').textContent = sh2.toFixed(1);
        tr.querySelector('.att-calc-total').textContent = total.toFixed(1);

        // Update summary totals
        let grandTotal = 0;
        document.querySelectorAll('.att-calc-total').forEach(el => {
            grandTotal += parseFloat(el.textContent) || 0;
        });
        const thEl = document.getElementById('attTotalHours');
        if (thEl) thEl.textContent = grandTotal.toFixed(1);
        const phEl = document.getElementById('attProgressHours');
        if (phEl) phEl.textContent = grandTotal.toFixed(1);
        const targetH = 200;
        const pct = Math.min((grandTotal / targetH) * 100, 100);
        const ppEl = document.getElementById('attProgressPct');
        if (ppEl) ppEl.textContent = pct.toFixed(0);
        const pbEl = document.getElementById('attProgressBar');
        if (pbEl) pbEl.style.width = pct + '%';
    }

    function saveAllDailyAttendance() {
        const rows = [];
        document.querySelectorAll('.att-table-edit tbody tr').forEach(tr => {
            const date = tr.getAttribute('data-date');
            if (!date) return;
            const ci = tr.querySelector('[data-col="check_in"]')?.value || '';
            const co = tr.querySelector('[data-col="check_out"]')?.value || '';
            const s3 = tr.querySelector('[data-col="scan_3"]')?.value || '';
            const s4 = tr.querySelector('[data-col="scan_4"]')?.value || '';
            const sts = tr.querySelector('[data-col="status"]')?.value || '';
            if (ci || co || s3 || s4 || sts) {
                rows.push({
                    date,
                    check_in: ci,
                    check_out: co,
                    scan_3: s3,
                    scan_4: s4,
                    status: sts || 'present'
                });
            }
        });

        if (rows.length === 0) {
            alert('Tidak ada data untuk disimpan');
            return;
        }

        const statusEl = document.getElementById('attSaveStatus');
        if (statusEl) statusEl.textContent = 'Menyimpan...';

        const formData = new FormData();
        formData.append('ajax_save_daily_attendance', '1');
        formData.append('employee_id', currentAttEmpId);
        formData.append('rows', JSON.stringify(rows));

        fetch('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (statusEl) statusEl.textContent = '✅ Tersimpan!';
                    setTimeout(() => {
                        if (statusEl) statusEl.textContent = '';
                    }, 3000);
                    // Update the slip row if data returned
                    if (data.slip) {
                        const slipId = data.slip.slip_id;
                        const whInput = document.querySelector(`tr[data-id="${slipId}"] .cell-hours input`);
                        if (whInput) whInput.value = parseFloat(data.slip.work_hours).toFixed(1);
                    }
                } else {
                    if (statusEl) statusEl.textContent = '❌ Error: ' + (data.message || 'Gagal');
                }
            })
            .catch(err => {
                if (statusEl) statusEl.textContent = '❌ Network error';
            });
    }
</script>

<!-- Floating Pay Selection Bar -->
<div id="paySelectionBar" style="display:none; position:fixed; bottom:1rem; left:50%; transform:translateX(-50%); background:linear-gradient(135deg,#059669,#10b981); color:#fff; padding:0.75rem 1.5rem; border-radius:50px; box-shadow:0 4px 20px rgba(0,0,0,0.3); z-index:1000; align-items:center; gap:1rem; font-size:0.85rem;">
    <span><strong id="paySelCount">0</strong> staff dipilih</span>
    <span style="opacity:0.8;">|</span>
    <span>Total: <strong>Rp <span id="paySelTotal">0</span></strong></span>
    <button onclick="paySelected()" style="background:#fff; color:#059669; border:none; padding:0.5rem 1rem; border-radius:25px; font-weight:600; cursor:pointer; margin-left:0.5rem;">
        💰 Bayar Selected
    </button>
</div>
<form id="paySelForm" method="POST" style="display:none;">
    <input type="hidden" name="quick_pay_selected" value="1">
    <input type="hidden" name="selected_slips" id="paySelSlipIds" value="">
</form>

<?php include '../../includes/footer.php'; ?>