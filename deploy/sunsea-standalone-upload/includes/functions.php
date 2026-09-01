<?php

/**
 * Helper Functions
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

/**
 * Bulatkan jam lembur ke JAM PENUH dengan threshold 45 menit.
 * Business rule baru: lembur < 45 menit tidak terhitung; selebihnya
 * dibulatkan ke bawah ke kelipatan 45 menit lalu diambil JAM penuh-nya.
 *
 *   0-44 menit   -> 0 jam
 *   45-89 menit  -> 1 jam
 *   90-134 menit -> 2 jam
 *   135-179 menit-> 3 jam
 *   180-224 menit-> 4 jam
 *
 * @param float|int|string|null $hours Jumlah jam lembur mentah (boleh desimal)
 * @return float Jam lembur penuh setelah dibulatkan (integer dalam tipe float)
 */
function roundOT45($hours)
{
    $h = (float)$hours;
    if ($h <= 0) return 0.0;
    $minutes = (int)floor($h * 60 + 0.5); // round to nearest minute first
    if ($minutes < 45) return 0.0;
    return (float) intdiv($minutes, 45); // 45→1, 89→1, 90→2, 134→2, 135→3
}

/**
 * Deteksi status "Terlambat" berdasarkan jam scan masuk (check-in), terlepas
 * dari status yang tersimpan di database (yang seringkali masih 'present'
 * karena threshold jadwal per-staff tidak pernah di-setup — jadwal staff
 * sering berubah-ubah sehingga tidak praktis untuk di-setup per orang).
 *
 * Aturan:
 *   - Check-in setelah jam 08:15 dianggap Terlambat.
 *   - KECUALI check-in setelah jam 12:00 siang — ini dianggap masuk shift
 *     split/siang, BUKAN terlambat.
 *   - Status yang sudah absent/leave/holiday tetap diprioritaskan (tidak
 *     ditimpa jadi 'late').
 *
 * @param string|null $status Status tersimpan di DB (present/late/absent/leave/holiday/half_day)
 * @param string|null $checkInTime Jam check-in dalam format H:i atau H:i:s
 * @return string Status efektif untuk ditampilkan
 */
function payrollDetectLateArrival(?string $status, ?string $checkInTime): string
{
    $lateStart = '08:15:00';
    $splitShiftCutoff = '12:00:00';
    $ci = $checkInTime ? substr($checkInTime, 0, 8) : '';
    // Normalize "H:i" -> "H:i:s" for consistent string comparison.
    if ($ci && strlen($ci) === 5) $ci .= ':00';

    if ($ci && $ci > $lateStart && $ci <= $splitShiftCutoff
        && !in_array($status, ['absent', 'leave', 'holiday'], true)) {
        return 'late';
    }
    return $status ?? '';
}

if (!function_exists('payrollAttendanceHours')) {
    /**
     * Hitung jam absensi bulanan utk payroll. Sama persis dgn aturan di
     * modules/payroll/process.php::getAttendanceHours() — dikutip ke sini
     * agar bisa dipakai dari halaman lain (mis. print-submission).
     */
    function payrollAttendanceHours($db, $empId, $month, $year)
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
        }

        $standardWorkDays = 26;
        $totalHours = 0;
        $totalOvertimeHours = 0;
        $extraHours = 0;
        $extraDays = 0;
        $daysWorked = 0;
        foreach ($rows as $r) {
            $wh = (float)$r['work_hours'];
            $manualOT = (float)($r['overtime_hours'] ?? 0);
            if ($wh <= 0) {
                $shift1 = 0;
                $shift2 = 0;
                if (!empty($r['shift_1_hours']) && (float)$r['shift_1_hours'] > 0) {
                    $shift1 = (float)$r['shift_1_hours'];
                } elseif (!empty($r['check_in_time']) && !empty($r['check_out_time'])) {
                    $t1 = strtotime($r['check_in_time']);
                    $t2 = strtotime($r['check_out_time']);
                    if ($t2 > $t1) $shift1 = round(($t2 - $t1) / 3600, 2);
                }
                if (!empty($r['shift_2_hours']) && (float)$r['shift_2_hours'] > 0) {
                    $shift2 = (float)$r['shift_2_hours'];
                } elseif (!empty($r['scan_3']) && !empty($r['scan_4'])) {
                    $t3 = strtotime($r['scan_3']);
                    $t4 = strtotime($r['scan_4']);
                    if ($t4 > $t3) $shift2 = round(($t4 - $t3) / 3600, 2);
                }
                $wh = round($shift1 + $shift2, 2);
                if ($wh <= 0) continue;
            }
            $daysWorked++;
            $cappedDay = min($wh, 8);

            if ($daysWorked <= $standardWorkDays) {
                $totalHours += $cappedDay;
            } else {
                $extraHours += $cappedDay;
                $extraDays++;
            }

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
            'auto_overtime_over_200' => 0.0,
        ];
    }
}

function sanitize($data)
{
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize($value);
        }
        return $data;
    }
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header("Location: " . $url);
    exit;
}

function getRequestMethod()
{
    return $_SERVER['REQUEST_METHOD'];
}

function isPost()
{
    return getRequestMethod() === 'POST';
}

function isGet()
{
    return getRequestMethod() === 'GET';
}

function getPost($key = null, $default = null)
{
    if ($key === null) {
        return $_POST;
    }
    return $_POST[$key] ?? $default;
}

function getGet($key = null, $default = null)
{
    if ($key === null) {
        return $_GET;
    }
    return $_GET[$key] ?? $default;
}

function setFlash($key, $message)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][$key] = $message;
}

function getFlash($key)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

// Alias untuk setFlash (compatibility)
function setFlashMessage($key, $message)
{
    setFlash($key, $message);
}

function formatCurrency($amount)
{
    // Hardcode Rp untuk menghindari encoding issue di hosting
    $symbol = 'Rp';
    $decimal = 0;

    // Use constants if they are valid (not corrupted)
    if (defined('CURRENCY_SYMBOL') && is_string(CURRENCY_SYMBOL) && strlen(CURRENCY_SYMBOL) <= 5) {
        $symbol = CURRENCY_SYMBOL;
    }
    if (defined('CURRENCY_DECIMAL') && is_numeric(CURRENCY_DECIMAL) && CURRENCY_DECIMAL >= 0 && CURRENCY_DECIMAL <= 4) {
        $decimal = (int)CURRENCY_DECIMAL;
    }

    return $symbol . ' ' . number_format((float)$amount, $decimal, ',', '.');
}

function formatDate($date, $format = DATE_FORMAT)
{
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = DATETIME_FORMAT)
{
    return date($format, strtotime($datetime));
}

function generateRandomString($length = 10)
{
    return bin2hex(random_bytes($length / 2));
}

function isToday($date)
{
    return date('Y-m-d', strtotime($date)) === date('Y-m-d');
}

function getDateRange($period = 'today')
{
    $start = '';
    $end = date('Y-m-d');

    switch ($period) {
        case 'today':
            $start = date('Y-m-d');
            break;
        case 'yesterday':
            $start = $end = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $start = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'month':
            $start = date('Y-m-01');
            break;
        case 'year':
            $start = date('Y-01-01');
            break;
    }

    return ['start' => $start, 'end' => $end];
}

function dd($data, $die = true)
{
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    if ($die) {
        die();
    }
}

function activeMenu($page)
{
    $currentPage = basename($_SERVER['PHP_SELF']);
    $currentPath = $_SERVER['PHP_SELF'];

    // For dashboard - only match if in root directory
    if ($page === 'index.php') {
        // Check if we're in root directory (not in modules folder)
        if ($currentPage === 'index.php' && strpos($currentPath, '/modules/') === false) {
            return 'active';
        }
        return '';
    }

    // For settings index page
    if ($page === 'settings-index') {
        if (strpos($currentPath, '/settings/') !== false && $currentPage === 'index.php') {
            return 'active';
        }
        return '';
    }

    // For reports menu - mark active if in any reports page
    if ($page === 'reports') {
        $reportPages = ['daily.php', 'monthly.php', 'yearly.php', 'detailed.php', 'by-division.php', 'index.php'];
        if (strpos($currentPath, '/reports/') !== false && in_array($currentPage, $reportPages)) {
            return 'active';
        }
        return '';
    }

    // Exact match for specific file names (daily.php, monthly.php, etc)
    if ($currentPage === $page) {
        return 'active';
    }

    // For module folders - check if path contains the module name
    if (strpos($currentPath, '/' . $page . '/') !== false) {
        return 'active';
    }

    return '';
}
