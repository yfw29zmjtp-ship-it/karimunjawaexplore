<?php

/**
 * Staff Portal API
 * Handles: register, login, get data, attendance, breakfast request
 * Public API — staff auth via session
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// ── Resolve Business ──
$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
if (!$bizSlug || !file_exists($bizFile)) {
    echo json_encode(['success' => false, 'message' => 'Invalid business']);
    exit;
}
$bizConfig = require $bizFile;
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizConfig['business_id']);
$db = Database::switchDatabase($bizConfig['database']);
$pdo = $db->getConnection();
$pdo->exec("SET time_zone = '+07:00'");

try {
    $pdo->query("SELECT overtime_hours FROM payroll_attendance LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE payroll_attendance ADD COLUMN overtime_hours DECIMAL(5,2) DEFAULT NULL AFTER work_hours");
}

// Ensure extra_hours exists on payroll_slips for consistent overtime display in staff portal.
try {
    $pdo->query("SELECT extra_hours FROM payroll_slips LIMIT 0");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE payroll_slips ADD COLUMN extra_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    } catch (Throwable $e2) {
    }
}

// Auto-create staff_accounts table
$pdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `plain_password` VARCHAR(255) DEFAULT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp (employee_id),
    UNIQUE KEY uk_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try {
    $pdo->exec("ALTER TABLE staff_accounts ADD COLUMN plain_password VARCHAR(255) DEFAULT NULL AFTER password_hash");
} catch (Exception $e) {
}

// Auto-create leave_requests table
$pdo->exec("CREATE TABLE IF NOT EXISTS `leave_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `leave_type` VARCHAR(50) NOT NULL DEFAULT 'cuti',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` TEXT,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `approved_by` VARCHAR(100) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `admin_notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp (employee_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Auto-create overtime_requests table
$pdo->exec("CREATE TABLE IF NOT EXISTS `overtime_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `overtime_date` DATE NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `approved_by` VARCHAR(100) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `admin_notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp (employee_id),
    INDEX idx_status (status),
    INDEX idx_date (overtime_date),
    UNIQUE KEY uk_emp_date (employee_id, overtime_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Session — close any existing session first, then start staff-specific one
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_name('staff_portal_' . md5($bizSlug));
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════
// REGISTER
// ══════════════════════════════════════
if ($action === 'register') {
    $empInput = trim($_POST['employee_code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$empInput || !$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter']);
        exit;
    }

    // Build employee_code from number input: "1" -> try EMP-001, EMP-01, EMP-1, or exact match
    $emp = null;
    if (ctype_digit($empInput)) {
        $variations = [
            'EMP-' . str_pad($empInput, 3, '0', STR_PAD_LEFT),
            'EMP-' . str_pad($empInput, 2, '0', STR_PAD_LEFT),
            'EMP-' . $empInput,
        ];
        foreach ($variations as $code) {
            $emp = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE employee_code = ? AND is_active = 1", [$code]);
            if ($emp) break;
        }
    }
    if (!$emp) {
        $emp = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE employee_code = ? AND is_active = 1", [strtoupper($empInput)]);
    }
    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Kode karyawan tidak ditemukan. Hubungi admin.']);
        exit;
    }

    // Check if already registered
    $exists = $db->fetchOne("SELECT id FROM staff_accounts WHERE employee_id = ?", [$emp['id']]);
    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'Karyawan sudah terdaftar. Gunakan login.']);
        exit;
    }
    $emailExists = $db->fetchOne("SELECT id FROM staff_accounts WHERE email = ?", [$email]);
    if ($emailExists) {
        echo json_encode(['success' => false, 'message' => 'Email sudah terdaftar.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $pdo->prepare("INSERT INTO staff_accounts (employee_id, email, password_hash, plain_password) VALUES (?, ?, ?, ?)")
            ->execute([$emp['id'], $email, $hash, $password]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Registrasi berhasil! Silakan login.', 'name' => $emp['full_name']]);
    exit;
}

// ══════════════════════════════════════
// LOGIN
// ══════════════════════════════════════
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Username & password wajib diisi']);
        exit;
    }

    $account = $db->fetchOne("SELECT sa.*, pe.full_name, pe.employee_code, pe.position, pe.department 
        FROM staff_accounts sa 
        JOIN payroll_employees pe ON pe.id = sa.employee_id 
        WHERE LOWER(sa.email) = LOWER(?)", [$email]);

    if (!$account) {
        echo json_encode(['success' => false, 'message' => 'Username tidak ditemukan']);
        exit;
    }
    if (!password_verify($password, $account['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Password salah']);
        exit;
    }

    // Update last login
    $pdo->prepare("UPDATE staff_accounts SET last_login = NOW() WHERE id = ?")->execute([$account['id']]);

    $_SESSION['staff_id'] = $account['id'];
    $_SESSION['employee_id'] = $account['employee_id'];
    $_SESSION['staff_name'] = $account['full_name'];
    $_SESSION['staff_code'] = $account['employee_code'];
    $_SESSION['staff_position'] = $account['position'];
    $_SESSION['staff_logged_in'] = true;

    echo json_encode(['success' => true, 'message' => 'Login berhasil', 'name' => $account['full_name'], 'employee_id' => (int)$account['employee_id']]);
    exit;
}

// ══════════════════════════════════════
// CHANGE PASSWORD
// ══════════════════════════════════════
if ($action === 'change_password') {
    $email = trim($_POST['email'] ?? '');
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$email || !$oldPassword || !$newPassword || !$confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi']);
        exit;
    }
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password baru minimal 6 karakter']);
        exit;
    }
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Konfirmasi password tidak cocok']);
        exit;
    }

    $account = $db->fetchOne("SELECT id, password_hash FROM staff_accounts WHERE LOWER(email) = LOWER(?)", [$email]);
    if (!$account) {
        echo json_encode(['success' => false, 'message' => 'Username/email tidak ditemukan']);
        exit;
    }
    if (!password_verify($oldPassword, $account['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Password lama salah']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE staff_accounts SET password_hash = ?, plain_password = ? WHERE id = ?")->execute([$newHash, $newPassword, $account['id']]);

    echo json_encode(['success' => true, 'message' => 'Password berhasil diubah! Silakan login dengan password baru.']);
    exit;
}

// Hotel operational date: day changes at noon (12:00), not midnight
// Before noon = still yesterday's hotel day (guests haven't checked out)
function getHotelDate()
{
    return (int)date('H') < 12 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
}

// Breakfast operational date: day changes at 10:00 (aligned with frontdesk breakfast module)
function getBreakfastHotelDate()
{
    return (int)date('H') < 10 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
}

// ══════════════════════════════════════
// LOGOUT
// ══════════════════════════════════════
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// ══════════════════════════════════════
// AUTH CHECK — all below require login
// ══════════════════════════════════════
if (empty($_SESSION['staff_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated', 'auth' => false]);
    exit;
}

$empId = (int)$_SESSION['employee_id'];

// ── GET PROFILE ──
if ($action === 'profile') {
    $emp = $db->fetchOne("SELECT id, employee_code, full_name, position, department, phone, join_date FROM payroll_employees WHERE id = ?", [$empId]);
    echo json_encode(['success' => true, 'data' => $emp]);
    exit;
}

// ── ATTENDANCE TODAY ──
if ($action === 'attendance_today') {
    $today = date('Y-m-d');
    $att = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$empId, $today]);
    // Enrich with schedule info for cafe
    if ($att) {
        $att['schedule_start'] = $att['schedule_start'] ?? null;
        $att['schedule_end'] = $att['schedule_end'] ?? null;
        $att['late_minutes'] = $att['late_minutes'] ?? 0;
        $att['early_leave_minutes'] = $att['early_leave_minutes'] ?? 0;
        $att['overtime_hours'] = (float)($att['overtime_hours'] ?? 0);
        if ($att['overtime_hours'] <= 0) {
            try {
                $ot = $db->fetchOne("SELECT 1 FROM overtime_requests WHERE employee_id = ? AND overtime_date = ? AND status = 'approved'", [$empId, $today]);
                if ($ot) {
                    $att['overtime_hours'] = max(0, round(((float)($att['work_hours'] ?? 0)) - 8, 2));
                }
            } catch (Exception $e) {
                // ignore and keep overtime_hours at 0
            }
        }
    }
    echo json_encode(['success' => true, 'data' => $att]);
    exit;
}

// ── ATTENDANCE HISTORY (current month) ──
if ($action === 'attendance_history') {
    $month = $_GET['month'] ?? date('Y-m');
    // ORDER ASC dulu untuk hitung akumulasi 200 jam dengan benar
    $rowsAsc = $db->fetchAll("SELECT attendance_date, check_in_time, check_out_time, scan_3, scan_4, work_hours, overtime_hours, shift_1_hours, shift_2_hours, status, notes FROM payroll_attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? ORDER BY attendance_date ASC", [$empId, $month]);

    // Summary
    $totalHours = 0;
    $totalRegular = 0;
    $totalOT = 0;
    $totalAutoOver200 = 0;
    $present = 0;
    $late = 0;
    $monthlyRegularCap = 200.0;
    $cumulativeRegular = 0.0;
    $autoOTByDate = []; // attendance_date => auto-OT hours (exact, untuk badge & display)

    // Get overtime submissions for this employee in the requested month.
    // Only overtime with status 'approved' will allow OT counting (pending requests do not grant OT).
    $overtimeDates = [];
    try {
        $ots = $db->fetchAll("SELECT overtime_date FROM overtime_requests WHERE employee_id = ? AND DATE_FORMAT(overtime_date, '%Y-%m') = ? AND status = 'approved'", [$empId, $month]) ?: [];
        foreach ($ots as $o) {
            $overtimeDates[(string)($o['overtime_date'] ?? '')] = true;
        }
    } catch (Exception $e) {
        // ignore DB errors and assume no overtime submissions
        $overtimeDates = [];
    }

    foreach ($rowsAsc as $r) {
        $wh = (float)($r['work_hours'] ?? 0);
        $otManual = (float)($r['overtime_hours'] ?? 0);
        $attDate = (string)($r['attendance_date'] ?? '');
        $cappedDay = min($wh, 8);

        // Bagi cappedDay → regular vs auto-OT berdasarkan akumulasi 200 jam
        $autoOTDay = 0.0;
        if ($cumulativeRegular >= $monthlyRegularCap) {
            $autoOTDay = $cappedDay;
            $regularDay = 0.0;
        } elseif ($cumulativeRegular + $cappedDay > $monthlyRegularCap) {
            $regularDay = $monthlyRegularCap - $cumulativeRegular;
            $autoOTDay = $cappedDay - $regularDay;
        } else {
            $regularDay = $cappedDay;
        }
        $cumulativeRegular += $regularDay;
        $totalHours += $regularDay; // total_hours = regular saja (sesuai cap 200)
        $totalRegular += $regularDay;
        if ($autoOTDay > 0) {
            $autoOTByDate[$attDate] = $autoOTDay;
            $totalAutoOver200 += $autoOTDay;
            $totalOT += $autoOTDay; // exact, tanpa rounding 45m
        }

        // Manual OT atau approved request: tetap dihitung dengan rounding 45 menit
        if ($otManual > 0) {
            $totalOT += roundOT45($otManual);
        } elseif (!empty($overtimeDates[$attDate])) {
            $totalOT += roundOT45(max(0, $wh - 8));
        }

        if ($r['status'] === 'present' || $r['status'] === 'late') $present++;
        if ($r['status'] === 'late') $late++;
    }

    // Build display rows (DESC order seperti sebelumnya)
    $rows = array_reverse($rowsAsc);
    foreach ($rows as &$rr) {
        $orig = (float)($rr['work_hours'] ?? 0);
        $attDate = (string)($rr['attendance_date'] ?? '');
        $manualOT = (float)($rr['overtime_hours'] ?? 0);
        $hasApprovedOT = !empty($overtimeDates[$attDate]);
        $autoOT = $autoOTByDate[$attDate] ?? 0;
        $rr['auto_overtime_over_200'] = round($autoOT, 2);
        $rr['is_over_200'] = $autoOT > 0;

        if ($manualOT > 0) {
            $rr['overtime_hours'] = round(roundOT45($manualOT) + $autoOT, 2);
        } elseif ($hasApprovedOT) {
            $rr['overtime_hours'] = round(roundOT45(max(0, $orig - 8)) + $autoOT, 2);
        } elseif ($autoOT > 0) {
            $rr['overtime_hours'] = round($autoOT, 2);
        } else {
            $rr['overtime_hours'] = 0;
            if ($orig > 8) $rr['work_hours'] = 8; // tampilkan 8 jam saat tidak ada OT
        }
    }
    unset($rr);

    echo json_encode(['success' => true, 'data' => $rows, 'summary' => [
        'total_hours' => round($totalHours, 1),
        'regular_hours' => round($totalRegular, 1),
        'overtime_hours' => round($totalOT, 1),
        'auto_overtime_over_200' => round($totalAutoOver200, 2),
        'days_present' => $present,
        'days_late' => $late,
        'target' => 200
    ]]);
    exit;
}

// ── ROOM OCCUPANCY ──
if ($action === 'occupancy') {
    $today = date('Y-m-d');
    $hotelDate = getHotelDate();
    $hotelTomorrow = date('Y-m-d', strtotime($hotelDate . ' +1 day'));
    try {
        $totalRooms = $db->fetchOne("SELECT COUNT(*) as c FROM rooms")['c'] ?? 0;
        $occupied = $db->fetchOne("SELECT COUNT(DISTINCT room_id) as c FROM bookings WHERE status = 'checked_in'")['c'] ?? 0;
        $available = max(0, $totalRooms - $occupied);
        $rate = $totalRooms > 0 ? round($occupied / $totalRooms * 100, 1) : 0;

        // Room list with type info + B2B detection
        $rooms = $db->fetchAll("SELECT r.id, r.room_number, r.floor_number,
            COALESCE(rt.type_name, 'Standard') as room_type,
            CASE
                WHEN b.id IS NOT NULL THEN 'occupied'
                WHEN r.status = 'cleaning' THEN 'cleaning'
                WHEN r.status = 'maintenance' THEN 'maintenance'
                WHEN r.status = 'blocked' THEN 'blocked'
                ELSE 'available'
            END as status,
            g.guest_name, b.check_in_date, b.check_out_date,
            (SELECT g2.guest_name FROM bookings b2 LEFT JOIN guests g2 ON b2.guest_id = g2.id
             WHERE b2.room_id = r.id AND DATE(b2.check_in_date) = ? AND b2.status IN ('confirmed','pending')
             LIMIT 1) as next_guest
            FROM rooms r
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
            LEFT JOIN guests g ON b.guest_id = g.id
            ORDER BY rt.type_name ASC, r.room_number ASC", [$hotelTomorrow]) ?: [];

        // Arrivals today (confirmed bookings checking in today)
        $arrivals = $db->fetchOne("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_in_date) = ? AND status IN ('confirmed','pending')", [$today])['c'] ?? 0;
        // Departures today (checked-in guests checking out today)
        $departures = $db->fetchOne("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_out_date) = ? AND status = 'checked_in'", [$today])['c'] ?? 0;

        // Calendar bookings (14 days from start_date param or today)
        $startDate = $_GET['start'] ?? $today;
        $endDate = date('Y-m-d', strtotime($startDate . ' +13 days'));
        $bookings = $db->fetchAll("SELECT b.id, b.booking_code, b.room_id, b.check_in_date, b.check_out_date,
            b.status, b.booking_source, b.payment_status, g.guest_name
            FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.check_in_date <= ? AND b.check_out_date > ?
            AND b.status IN ('pending','confirmed','checked_in','checked_out')
            ORDER BY b.check_in_date ASC", [$endDate, $startDate]) ?: [];

        echo json_encode(['success' => true, 'data' => [
            'total_rooms' => $totalRooms,
            'occupied' => $occupied,
            'available' => $available,
            'occupancy_rate' => $rate,
            'rooms' => $rooms,
            'arrivals_today' => $arrivals,
            'departures_today' => $departures,
            'bookings' => $bookings,
            'calendar_start' => $startDate,
            'calendar_end' => $endDate
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => ['total_rooms' => 0, 'occupied' => 0, 'available' => 0, 'occupancy_rate' => 0, 'rooms' => [], 'arrivals_today' => 0, 'departures_today' => 0, 'bookings' => []]]);
    }
    exit;
}

// ── MARK ROOM CLEAN (staff dapat menandai kamar yang dirty menjadi bersih) ──
if ($action === 'mark_room_clean') {
    $roomId = (int)($_POST['room_id'] ?? $_GET['room_id'] ?? 0);
    if ($roomId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Room ID tidak valid']);
        exit;
    }
    try {
        $room = $db->fetchOne("SELECT id, room_number, status FROM rooms WHERE id = ?", [$roomId]);
        if (!$room) {
            echo json_encode(['success' => false, 'message' => 'Kamar tidak ditemukan']);
            exit;
        }
        if ($room['status'] !== 'cleaning') {
            echo json_encode(['success' => true, 'message' => 'Kamar tidak dalam status dirty', 'room_status' => $room['status']]);
            exit;
        }
        $db->query("UPDATE rooms SET status = 'available', updated_at = NOW() WHERE id = ? AND status = 'cleaning'", [$roomId]);
        echo json_encode(['success' => true, 'message' => 'Kamar ' . $room['room_number'] . ' sudah bersih', 'room_status' => 'available']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ── BREAKFAST REQUEST ──
if ($action === 'breakfast_menu') {
    try {
        $menus = $db->fetchAll("SELECT id, menu_name, category, is_free FROM breakfast_menus WHERE is_available = 1 ORDER BY category, menu_name") ?: [];
        echo json_encode(['success' => true, 'data' => $menus]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => []]);
    }
    exit;
}

if ($action === 'breakfast_submit') {
    $menuId = (int)($_POST['menu_id'] ?? 0);
    $date = $_POST['date'] ?? getBreakfastHotelDate();
    $staffName = $_SESSION['staff_name'] ?? 'Staff';

    if ($menuId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Pilih menu']);
        exit;
    }

    try {
        $menu = $db->fetchOne("SELECT menu_name FROM breakfast_menus WHERE id = ?", [$menuId]);
        if (!$menu) {
            echo json_encode(['success' => false, 'message' => 'Menu tidak ditemukan']);
            exit;
        }

        // Check if already ordered today
        $existing = $db->fetchOne("SELECT id FROM breakfast_orders WHERE guest_name = ? AND breakfast_date = ?", [$staffName, $date]);
        if ($existing) {
            $pdo->prepare("UPDATE breakfast_orders SET menu_name = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$menu['menu_name'], $existing['id']]);
            echo json_encode(['success' => true, 'message' => 'Pesanan breakfast diperbarui: ' . $menu['menu_name']]);
        } else {
            $pdo->prepare("INSERT INTO breakfast_orders (guest_name, breakfast_date, menu_name, room_number, status) VALUES (?, ?, ?, ?, 'pending')")
                ->execute([$staffName, $date, $menu['menu_name'], 'STAFF']);
            echo json_encode(['success' => true, 'message' => 'Pesanan breakfast berhasil: ' . $menu['menu_name']]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'breakfast_today') {
    $hotelDate = getBreakfastHotelDate();
    $staffName = $_SESSION['staff_name'] ?? '';
    $order = $db->fetchOne("SELECT * FROM breakfast_orders WHERE guest_name = ? AND breakfast_date = ?", [$staffName, $hotelDate]);
    echo json_encode(['success' => true, 'data' => $order]);
    exit;
}

if ($action === 'breakfast_mark_completed') {
    $orderId = (int)($_POST['order_id'] ?? $_POST['id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID order tidak valid']);
        exit;
    }

    $today = getBreakfastHotelDate();
    try {
        $order = $db->fetchOne("SELECT id, order_status FROM breakfast_orders WHERE id = ? AND breakfast_date = ?", [$orderId, $today]);
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan untuk tanggal breakfast hari ini']);
            exit;
        }

        if (($order['order_status'] ?? '') === 'completed') {
            echo json_encode(['success' => true, 'message' => 'Order sudah completed']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE breakfast_orders SET order_status = 'completed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$orderId]);

        echo json_encode(['success' => true, 'message' => 'Order berhasil di-complete']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal update status: ' . $e->getMessage()]);
    }
    exit;
}

// ── ALL TODAY'S BREAKFAST ORDERS (Staff Monitor) ──
if ($action === 'breakfast_orders') {
    // Keep this aligned with frontdesk breakfast page and export-daily-report PDF.
    $today = getBreakfastHotelDate();
    try {
        $hasMenuNameColumn = false;
        try {
            $col = $db->fetchOne("SHOW COLUMNS FROM breakfast_orders LIKE 'menu_name'");
            $hasMenuNameColumn = !empty($col);
        } catch (Exception $e) {
            $hasMenuNameColumn = false;
        }

        $menuNameSelect = $hasMenuNameColumn ? ', bo.menu_name' : ', NULL AS menu_name';
        $rawOrders = $db->fetchAll("
            SELECT bo.id, bo.guest_name, bo.room_number, bo.total_pax, bo.breakfast_time,
                   bo.breakfast_date, bo.location, bo.menu_items, bo.special_requests,
                   bo.total_price, bo.order_status{$menuNameSelect}, bo.created_at
            FROM breakfast_orders bo
            WHERE bo.breakfast_date = ?
            ORDER BY bo.breakfast_time ASC, bo.id ASC
        ", [$today]) ?: [];

        // Decode + normalize room format, then keep only the newest row per guest/date/room key.
        $dedupMap = [];
        foreach ($rawOrders as $o) {
            $o['menu_items'] = json_decode($o['menu_items'] ?? '[]', true) ?: [];
            $o['total_pax'] = max(0, (int)($o['total_pax'] ?? 0));

            $rooms = json_decode($o['room_number'] ?? '[]', true);
            if (is_array($rooms)) {
                $normalizedRooms = array_values(array_unique(array_map('trim', $rooms)));
                sort($normalizedRooms, SORT_NATURAL);
                $o['room_display'] = implode(', ', $normalizedRooms);
            } else {
                $o['room_display'] = trim((string)($o['room_number'] ?? ''));
            }

            $guestKey = strtolower(trim((string)($o['guest_name'] ?? '')));
            $roomKey = strtolower(trim((string)$o['room_display']));
            $dateKey = (string)($o['breakfast_date'] ?? '');
            $dedupKey = $guestKey . '|' . $dateKey . '|' . $roomKey;

            if (!isset($dedupMap[$dedupKey]) || (int)$o['id'] > (int)$dedupMap[$dedupKey]['id']) {
                $dedupMap[$dedupKey] = $o;
            }
        }
        $orders = array_values($dedupMap);
        usort($orders, function ($a, $b) {
            $at = (string)($a['breakfast_time'] ?? '');
            $bt = (string)($b['breakfast_time'] ?? '');
            if ($at === $bt) {
                return (int)$a['id'] <=> (int)$b['id'];
            }
            return strcmp($at, $bt);
        });

        // Stats
        $totalOrders = count($orders);
        $totalPax = array_sum(array_column($orders, 'total_pax'));
        $statusCounts = ['pending' => 0, 'preparing' => 0, 'served' => 0, 'completed' => 0];
        foreach ($orders as $o) {
            $s = $o['order_status'] ?? 'pending';
            if (isset($statusCounts[$s])) $statusCounts[$s]++;
        }

        // Rekap total pesanan per menu (untuk kitchen prep)
        $menuRecap = [];
        foreach ($orders as $o) {
            foreach ($o['menu_items'] as $item) {
                $menuName = trim($item['menu_name'] ?? '');
                if ($menuName === '') continue;
                $qty = (int)($item['quantity'] ?? 1);
                if (!isset($menuRecap[$menuName])) $menuRecap[$menuName] = 0;
                $menuRecap[$menuName] += $qty;
            }
        }
        arsort($menuRecap);
        $menuRecapList = [];
        foreach ($menuRecap as $name => $qty) {
            $menuRecapList[] = ['menu_name' => $name, 'qty' => $qty];
        }

        echo json_encode(['success' => true, 'data' => [
            'orders' => $orders,
            'menu_recap' => $menuRecapList,
            'stats' => [
                'total_orders' => $totalOrders,
                'total_pax' => $totalPax,
                'status' => $statusCounts
            ]
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => ['orders' => [], 'stats' => ['total_orders' => 0, 'total_pax' => 0, 'status' => []]]]);
    }
    exit;
}

// ══════════════════════════════════════
// LEAVE / CUTI
// ══════════════════════════════════════
if ($action === 'leave_submit') {
    $type = trim($_POST['leave_type'] ?? 'cuti');
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Tanggal mulai dan selesai wajib diisi']);
        exit;
    }
    if ($start > $end) {
        echo json_encode(['success' => false, 'message' => 'Tanggal selesai harus setelah tanggal mulai']);
        exit;
    }
    if (!$reason) {
        echo json_encode(['success' => false, 'message' => 'Alasan wajib diisi']);
        exit;
    }
    $allowedTypes = ['cuti', 'sakit', 'izin', 'cuti_khusus'];
    if (!in_array($type, $allowedTypes)) $type = 'cuti';

    // Check overlapping
    $overlap = $db->fetchOne("SELECT id FROM leave_requests WHERE employee_id = ? AND status != 'rejected' AND start_date <= ? AND end_date >= ?", [$empId, $end, $start]);
    if ($overlap) {
        echo json_encode(['success' => false, 'message' => 'Sudah ada pengajuan cuti di tanggal tersebut']);
        exit;
    }

    try {
        $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)")
            ->execute([$empId, $type, $start, $end, $reason]);

        // Push notification to admin/owner
        try {
            require_once __DIR__ . '/../../includes/PushNotificationHelper.php';
            $pushHelper = new PushNotificationHelper($db);
            $staffName = $_SESSION['staff_name'] ?? 'Staff';
            $typeLabels = ['cuti' => 'Cuti', 'sakit' => 'Sakit', 'izin' => 'Izin', 'cuti_khusus' => 'Cuti Khusus'];
            $typeLabel = $typeLabels[$type] ?? $type;
            $pushHelper->sendToAdmins(
                "\xF0\x9F\x93\x8B Pengajuan {$typeLabel}: {$staffName}",
                "{$staffName} mengajukan {$typeLabel} tanggal {$start} s/d {$end}",
                [
                    'type' => 'leave_request',
                    'tag'  => 'leave-' . $empId . '-' . time(),
                    'url'  => '/modules/payroll/employees.php'
                ]
            );
        } catch (\Throwable $pushErr) {
            error_log('Push notification error (leave): ' . $pushErr->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Pengajuan cuti berhasil dikirim!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'leave_history') {
    $rows = $db->fetchAll("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 50", [$empId]) ?: [];
    // Count stats
    $year = date('Y');
    $stats = $db->fetchOne("SELECT 
        COUNT(CASE WHEN status = 'approved' AND leave_type = 'cuti' AND YEAR(start_date) = ? THEN 1 END) as cuti_used,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
        FROM leave_requests WHERE employee_id = ?", [$year, $empId]) ?: [];
    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);
    exit;
}

if ($action === 'notifications') {
    // Get notifications from notifications table (both leave + overtime responses)
    $notifs = $db->fetchAll("SELECT id, type, title, message, data, is_read, created_at 
        FROM notifications 
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY created_at DESC LIMIT 20", [$empId]) ?: [];
    // Also get legacy leave notifications if notifications table is empty
    if (empty($notifs)) {
        $legacy = $db->fetchAll("SELECT id, leave_type, start_date, end_date, status, admin_notes, approved_at 
            FROM leave_requests 
            WHERE employee_id = ? AND status IN ('approved','rejected') AND approved_at IS NOT NULL AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY approved_at DESC LIMIT 20", [$empId]) ?: [];
        echo json_encode(['success' => true, 'data' => $legacy, 'source' => 'legacy']);
        exit;
    }
    $unread = 0;
    foreach ($notifs as &$n) {
        $n['data'] = json_decode($n['data'], true);
        if (!$n['is_read']) $unread++;
    }
    echo json_encode(['success' => true, 'data' => $notifs, 'unread_count' => $unread, 'source' => 'notifications']);
    exit;
}

if ($action === 'notif_mark_read') {
    $db->query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$empId]);
    echo json_encode(['success' => true]);
    exit;
}

// ══════════════════════════════════════
// FACE SCAN — Get face data for logged-in staff
// ══════════════════════════════════════
if ($action === 'face_data') {
    $emp = $db->fetchOne("SELECT id, employee_code, full_name, position, department, face_descriptor FROM payroll_employees WHERE id = ? AND is_active = 1", [$empId]);
    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Data karyawan tidak ditemukan']);
        exit;
    }

    $today = date('Y-m-d');
    $att = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$empId, $today]);
    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1");
    $locRows = $db->fetchAll("SELECT id, location_name, lat, lng, radius_m FROM payroll_attendance_locations WHERE is_active = 1 ORDER BY id") ?: [];

    echo json_encode([
        'success' => true,
        'employee' => [
            'id' => (int)$emp['id'],
            'name' => $emp['full_name'],
            'has_face' => !empty($emp['face_descriptor']),
            'face_descriptor' => $emp['face_descriptor'] ? json_decode($emp['face_descriptor'], true) : null,
        ],
        'today' => $att,
        'config' => [
            'locations' => array_map(fn($l) => [
                'name' => $l['location_name'],
                'lat' => (float)$l['lat'],
                'lng' => (float)$l['lng'],
                'radius' => (int)$l['radius_m'],
            ], $locRows),
            'checkin_end' => $config['checkin_end'] ?? '10:00:00',
            'allow_outside' => (bool)($config['allow_outside'] ?? false),
        ],
    ]);
    exit;
}

// ══════════════════════════════════════
// FACE SCAN — Register face descriptor
// ══════════════════════════════════════
if ($action === 'face_register') {
    $descriptor = trim($_POST['face_descriptor'] ?? '');
    if (!$descriptor) {
        echo json_encode(['success' => false, 'message' => 'Data wajah kosong']);
        exit;
    }
    $arr = json_decode($descriptor, true);
    if (!is_array($arr) || count($arr) < 100) {
        echo json_encode(['success' => false, 'message' => 'Format descriptor wajah tidak valid']);
        exit;
    }
    $db->query("UPDATE payroll_employees SET face_descriptor = ? WHERE id = ?", [$descriptor, $empId]);
    echo json_encode(['success' => true, 'message' => 'Wajah berhasil didaftarkan!']);
    exit;
}

// ══════════════════════════════════════
// FACE SCAN — Clock in/out (split-shift like fingerprint)
// Business-type aware: cafe=2 scans, hotel=4 scans
// ══════════════════════════════════════
if ($action === 'face_clock') {
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    $address = substr(trim($_POST['address'] ?? ''), 0, 255);
    // 'manual' = fallback button when Face ID is slow/unavailable. GPS radius check
    // below is NOT skipped for manual mode - it is exactly the same mandatory check.
    $clockMode = ($_POST['mode'] ?? 'face') === 'manual' ? 'manual' : 'face';
    $today = date('Y-m-d');
    $now = date('H:i:s');

    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1");
    $allowOutside = (bool)($config['allow_outside'] ?? false);
    $checkinEnd = $config['checkin_end'] ?? '10:00:00';

    // Detect business type
    $isCafeBiz = in_array($bizConfig['business_type'] ?? '', ['cafe', 'restaurant']);

    // Location check
    $locRows = $db->fetchAll("SELECT * FROM payroll_attendance_locations WHERE is_active = 1") ?: [];
    $distance = 0;
    $isOutside = false;
    if (!empty($locRows) && $lat && $lng) {
        $nearest = null;
        $nearestDist = PHP_INT_MAX;
        foreach ($locRows as $loc) {
            $R = 6371000;
            $dLat = deg2rad((float)$loc['lat'] - $lat);
            $dLng = deg2rad((float)$loc['lng'] - $lng);
            $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat)) * cos(deg2rad((float)$loc['lat'])) * sin($dLng / 2) * sin($dLng / 2);
            $d = (int)(2 * $R * asin(sqrt($a)));
            if ($d < $nearestDist) {
                $nearestDist = $d;
                $nearest = $loc;
            }
        }
        $distance = $nearestDist;
        $isOutside = $distance > (int)$nearest['radius_m'];
        if ($isOutside && !$allowOutside) {
            echo json_encode(['success' => false, 'message' => "Di luar radius {$nearest['location_name']} ({$distance}m, maks {$nearest['radius_m']}m)"]);
            exit;
        }
    }

    // Human-readable note of which location/radius the scan was recorded at,
    // written into payroll_attendance.notes and the response message so both
    // staff and admin can see it ("absen dalam radius berapa").
    $radiusNote = '';
    $radiusMsg = '';
    if (isset($nearest) && $nearest) {
        $radiusNote = " \xe2\x80\x94 {$distance}m dari {$nearest['location_name']}" . ($isOutside ? ' (luar radius)' : '');
        $radiusMsg = " ({$distance}m dari {$nearest['location_name']})";
    }

    // Get existing attendance
    $pdo = $db->getConnection();

    // Ensure split-shift columns exist
    try {
        $pdo->query("SELECT scan_3 FROM payroll_attendance LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE payroll_attendance ADD COLUMN scan_3 TIME DEFAULT NULL AFTER check_out_time, ADD COLUMN scan_4 TIME DEFAULT NULL AFTER scan_3, ADD COLUMN shift_1_hours DECIMAL(5,2) DEFAULT NULL AFTER work_hours, ADD COLUMN shift_2_hours DECIMAL(5,2) DEFAULT NULL AFTER shift_1_hours");
    }

    // Ensure late/early columns for cafe
    if ($isCafeBiz) {
        try {
            $pdo->query("SELECT late_minutes FROM payroll_attendance LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE payroll_attendance ADD COLUMN late_minutes INT DEFAULT 0 AFTER notes, ADD COLUMN early_leave_minutes INT DEFAULT 0 AFTER late_minutes, ADD COLUMN schedule_start TIME DEFAULT NULL AFTER early_leave_minutes, ADD COLUMN schedule_end TIME DEFAULT NULL AFTER schedule_start");
        }
    }

    $att = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$empId, $today]);

    if ($isCafeBiz) {
        // ── CAFE MODE: 2 scans (check_in, check_out) with fixed schedule ──
        $scan1 = $att['check_in_time'] ?? null;
        $scan2 = $att['check_out_time'] ?? null;

        // Double scan filter (15 min for cafe)
        if ($scan1 && !$scan2) {
            $diffMin = abs(strtotime("2000-01-01 " . $now) - strtotime("2000-01-01 " . $scan1)) / 60;
            if ($diffMin < 15) {
                echo json_encode(['success' => false, 'message' => 'Baru saja absen masuk ' . substr($scan1, 0, 5) . ' (' . round($diffMin) . ' menit lalu). Tunggu minimal 15 menit.']);
                exit;
            }
        }

        if ($scan1 && $scan2) {
            echo json_encode(['success' => false, 'message' => 'Sudah absen masuk & pulang hari ini.']);
            exit;
        }

        // Get employee schedule
        $schedule = null;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `employee_id` INT NOT NULL,
                `day_of_week` TINYINT NOT NULL DEFAULT 0,
                `start_time` TIME NOT NULL DEFAULT '09:00:00',
                `end_time` TIME NOT NULL DEFAULT '17:00:00',
                `break_minutes` INT DEFAULT 60,
                `is_off` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_emp_day (employee_id, day_of_week)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $dayOfWeek = (int)date('w'); // 0=Sun, 6=Sat
            $schedule = $db->fetchOne("SELECT * FROM payroll_work_schedules WHERE employee_id = ? AND day_of_week = ?", [$empId, $dayOfWeek]);
        } catch (Exception $e) {
        }

        // Fallback to config times
        $schedStart = $schedule['start_time'] ?? ($config['checkin_start'] ?? '09:00:00');
        $schedEnd = $schedule['end_time'] ?? ($config['checkout_start'] ?? '17:00:00');
        $isScheduledOff = (bool)($schedule['is_off'] ?? false);

        $device = $clockMode === 'manual' ? 'manual:gps' : 'face:verified';

        try {
            if (!$att) {
                // CHECK-IN
                $lateMinutes = 0;
                if (strtotime("2000-01-01 $now") > strtotime("2000-01-01 $schedStart")) {
                    $lateMinutes = (int)((strtotime("2000-01-01 $now") - strtotime("2000-01-01 $schedStart")) / 60);
                }
                $status = ($lateMinutes > 5) ? 'late' : 'present'; // 5 min grace
                $statusEmoji = $status === 'late' ? ' ⚠️ Terlambat ' . $lateMinutes . ' menit' : '';

                $pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_in_lat, check_in_lng, check_in_distance_m, check_in_address, check_in_device, status, is_outside_radius, notes, late_minutes, schedule_start, schedule_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$empId, $today, $now, $lat ?: null, $lng ?: null, $distance, $address, $device, $status, $isOutside ? 1 : 0, 'Absen masuk' . $radiusNote, $lateMinutes, $schedStart, $schedEnd]);

                echo json_encode([
                    'success' => true,
                    'message' => '✅ Absen Masuk — ' . date('H:i') . $statusEmoji . $radiusMsg,
                    'scan_num' => 1
                ]);
                exit;
            }

            if (empty($scan2)) {
                // CHECK-OUT
                $workHours = max(0, round((strtotime("2000-01-01 $now") - strtotime("2000-01-01 $scan1")) / 3600, 2));
                $earlyLeave = 0;
                if (strtotime("2000-01-01 $now") < strtotime("2000-01-01 $schedEnd")) {
                    $earlyLeave = (int)((strtotime("2000-01-01 $schedEnd") - strtotime("2000-01-01 $now")) / 60);
                }
                $earlyNote = ($earlyLeave > 5) ? ' ⚠️ Pulang awal ' . $earlyLeave . ' menit' : '';

                $pdo->prepare("UPDATE payroll_attendance SET check_out_time = ?, check_out_lat = ?, check_out_lng = ?, check_out_distance_m = ?, check_out_device = ?, work_hours = ?, shift_1_hours = ?, early_leave_minutes = ?, notes = ? WHERE id = ?")
                    ->execute([$now, $lat ?: null, $lng ?: null, $distance, $device, $workHours, $workHours, $earlyLeave, 'Absen pulang' . $radiusNote, $att['id']]);

                echo json_encode([
                    'success' => true,
                    'message' => '✅ Absen Pulang — ' . date('H:i') . " ({$workHours} jam)" . $earlyNote . $radiusMsg,
                    'scan_num' => 2
                ]);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
            exit;
        }
    } else {
        // ── HOTEL MODE: 4 scans (split-shift) ──
        $scan1 = $att['check_in_time'] ?? null;
        $scan2 = $att['check_out_time'] ?? null;
        $scan3 = $att['scan_3'] ?? null;
        $scan4 = $att['scan_4'] ?? null;
        $filledScans = array_filter([$scan1, $scan2, $scan3, $scan4], fn($s) => !empty($s));

        // Double scan filter (5 min - prevent accidental double-tap)
        if (!empty($filledScans)) {
            $lastScan = end($filledScans);
            $diffMin = abs(strtotime("2000-01-01 " . $now) - strtotime("2000-01-01 " . $lastScan)) / 60;
            if ($diffMin < 5) {
                echo json_encode(['success' => false, 'message' => 'Scan terakhir ' . substr($lastScan, 0, 5) . ' (' . round($diffMin) . ' menit lalu). Tunggu minimal 5 menit.']);
                exit;
            }
        }

        if (count($filledScans) >= 4) {
            echo json_encode(['success' => false, 'message' => 'Sudah 4 scan hari ini (maks split-shift)']);
            exit;
        }

        $device = $clockMode === 'manual' ? 'manual:gps' : 'face:verified';
        $scanLabels = ['Masuk Shift 1', 'Pulang Shift 1', 'Masuk Shift 2', 'Pulang Shift 2'];
        $modeLabel = $clockMode === 'manual' ? 'Manual scan' : 'Face scan';

        try {
            if (!$att) {
                // Scan 1 — new record
                $status = ($now > $checkinEnd) ? 'late' : 'present';
                $pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_in_lat, check_in_lng, check_in_distance_m, check_in_address, check_in_device, status, is_outside_radius, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$empId, $today, $now, $lat ?: null, $lng ?: null, $distance, $address, $device, $status, $isOutside ? 1 : 0, $modeLabel . ' 1/4' . $radiusNote]);
                echo json_encode(['success' => true, 'message' => '✅ ' . $scanLabels[0] . ' — ' . date('H:i') . ($status === 'late' ? ' ⚠️ Terlambat' : '') . $radiusMsg, 'scan_num' => 1]);
                exit;
            }

            // Determine next empty slot
            $scanNum = 0;
            if (empty($scan1)) $scanNum = 1;
            elseif (empty($scan2)) $scanNum = 2;
            elseif (empty($scan3)) $scanNum = 3;
            elseif (empty($scan4)) $scanNum = 4;

            if ($scanNum === 0) {
                echo json_encode(['success' => false, 'message' => 'Sudah lengkap 4 scan']);
                exit;
            }

            $colMap = [1 => 'check_in_time', 2 => 'check_out_time', 3 => 'scan_3', 4 => 'scan_4'];
            $updates = [$colMap[$scanNum] . " = ?"];
            $params = [$now];

            // Calculate shift hours
            $shift1Hours = null;
            $shift2Hours = null;
            if ($scanNum === 2 && $scan1) {
                $shift1Hours = max(0, round((strtotime("2000-01-01 " . $now) - strtotime("2000-01-01 " . $scan1)) / 3600, 2));
                $updates[] = "shift_1_hours = ?";
                $params[] = $shift1Hours;
                $updates[] = "check_out_device = ?";
                $params[] = $device;
                if ($lat) {
                    $updates[] = "check_out_lat = ?";
                    $params[] = $lat;
                    $updates[] = "check_out_lng = ?";
                    $params[] = $lng;
                    $updates[] = "check_out_distance_m = ?";
                    $params[] = $distance;
                }
            }
            if ($scanNum === 4 && $scan3) {
                $shift2Hours = max(0, round((strtotime("2000-01-01 " . $now) - strtotime("2000-01-01 " . $scan3)) / 3600, 2));
                $updates[] = "shift_2_hours = ?";
                $params[] = $shift2Hours;
            }

            // Recalculate total
            $curS1 = ($shift1Hours !== null) ? $shift1Hours : (float)($att['shift_1_hours'] ?? 0);
            $curS2 = ($shift2Hours !== null) ? $shift2Hours : (float)($att['shift_2_hours'] ?? 0);
            $totalHours = round($curS1 + $curS2, 2);
            if ($totalHours > 0) {
                $updates[] = "work_hours = ?";
                $params[] = $totalHours;
            }

            $updates[] = "notes = ?";
            $params[] = "{$modeLabel} {$scanNum}/4{$radiusNote}";
            $params[] = $att['id'];

            $pdo->prepare("UPDATE payroll_attendance SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

            $hoursTxt = '';
            if ($scanNum === 2 && $shift1Hours) $hoursTxt = " ({$shift1Hours} jam)";
            if ($scanNum === 4 && $shift2Hours) $hoursTxt = " (total: {$totalHours} jam)";

            echo json_encode(['success' => true, 'message' => '✅ ' . $scanLabels[$scanNum - 1] . ' — ' . date('H:i') . $hoursTxt . $radiusMsg, 'scan_num' => $scanNum]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
            exit;
        }
    } // end hotel else
}

// ══════════════════════════════════════
// WORK SCHEDULE (Cafe)
// ══════════════════════════════════════
if ($action === 'work_schedule') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `day_of_week` TINYINT NOT NULL DEFAULT 0,
        `start_time` TIME NOT NULL DEFAULT '09:00:00',
        `end_time` TIME NOT NULL DEFAULT '17:00:00',
        `break_minutes` INT DEFAULT 60,
        `is_off` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_emp_day (employee_id, day_of_week)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1");
    $defaultStart = $config['checkin_start'] ?? '09:00:00';
    $defaultEnd = $config['checkout_start'] ?? '17:00:00';

    // Get today schedule
    $todayDow = (int)date('w');
    $todaySchedule = $db->fetchOne("SELECT * FROM payroll_work_schedules WHERE employee_id = ? AND day_of_week = ?", [$empId, $todayDow]);

    // Get week schedule
    $weekSchedule = $db->fetchAll("SELECT * FROM payroll_work_schedules WHERE employee_id = ? ORDER BY day_of_week", [$empId]) ?: [];

    // If no schedule, return defaults
    $startTime = $todaySchedule['start_time'] ?? $defaultStart;
    $endTime = $todaySchedule['end_time'] ?? $defaultEnd;
    $breakMin = $todaySchedule['break_minutes'] ?? 60;

    $totalHours = max(0, round((strtotime("2000-01-01 $endTime") - strtotime("2000-01-01 $startTime")) / 3600, 1));

    // Build weekly data
    $weekly = [];
    $schedMap = [];
    foreach ($weekSchedule as $ws) {
        $schedMap[(int)$ws['day_of_week']] = $ws;
    }
    for ($d = 0; $d <= 6; $d++) {
        if (isset($schedMap[$d])) {
            $weekly[] = [
                'day_index' => $d,
                'start_time' => $schedMap[$d]['start_time'],
                'end_time' => $schedMap[$d]['end_time'],
                'is_off' => (bool)$schedMap[$d]['is_off'],
            ];
        } else {
            $weekly[] = [
                'day_index' => $d,
                'start_time' => $defaultStart,
                'end_time' => $defaultEnd,
                'is_off' => false,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_minutes' => (int)$breakMin,
            'total_hours' => $totalHours,
            'weekly' => $weekly,
        ]
    ]);
    exit;
}

// ══════════════════════════════════════
// SALARY PERIODS — list available payroll periods for this employee
// ══════════════════════════════════════
if ($action === 'salary_periods') {
    try {
        // Get periods where this employee has a slip AND status is approved or paid
        $rows = $db->fetchAll("
            SELECT p.id, p.period_label, p.period_month, p.period_year, p.status,
                   CASE p.status 
                       WHEN 'paid' THEN '✅ Dibayar'
                       WHEN 'approved' THEN '✅ Disetujui'
                       WHEN 'submitted' THEN '⏳ Diproses'
                       ELSE '📝 Draft'
                   END as status_label
            FROM payroll_periods p
            INNER JOIN payroll_slips s ON s.period_id = p.id AND s.employee_id = ? AND s.is_paid = 1
            WHERE p.status IN ('approved', 'paid')
            ORDER BY p.period_year DESC, p.period_month DESC
            LIMIT 24
        ", [$empId]) ?: [];

        // Mark latest
        if (!empty($rows)) $rows[0]['is_latest'] = true;
        foreach ($rows as &$r) {
            if (!isset($r['is_latest'])) $r['is_latest'] = false;
        }

        echo json_encode(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => []]);
    }
    exit;
}

// ══════════════════════════════════════
// SALARY SLIP — get slip detail for a period
// ══════════════════════════════════════
if ($action === 'salary_slip') {
    $periodId = (int)($_GET['period_id'] ?? 0);
    if (!$periodId) {
        echo json_encode(['success' => false, 'message' => 'Period ID diperlukan']);
        exit;
    }

    try {
        // Verify period is approved/paid
        $period = $db->fetchOne("SELECT id, status, period_label FROM payroll_periods WHERE id = ? AND status IN ('approved', 'paid')", [$periodId]);
        if (!$period) {
            echo json_encode(['success' => false, 'message' => 'Slip gaji belum tersedia untuk periode ini', 'pending' => true]);
            exit;
        }

        $slip = $db->fetchOne("
            SELECT s.*, p.period_label, p.period_month, p.period_year,
                   e.bank_name, e.bank_account, e.employee_code, e.department, e.position
            FROM payroll_slips s
            JOIN payroll_periods p ON s.period_id = p.id
            LEFT JOIN payroll_employees e ON s.employee_id = e.id
            WHERE s.period_id = ? AND s.employee_id = ? AND s.is_paid = 1
        ", [$periodId, $empId]);

        if (!$slip) {
            echo json_encode(['success' => false, 'message' => 'Slip gaji tidak ditemukan untuk Anda di periode ini']);
            exit;
        }

        $otHours = (float)($slip['overtime_hours'] ?? 0);
        $otRate = (float)($slip['overtime_rate'] ?? 0);
        $otAmount = (float)($slip['overtime_amount'] ?? 0);
        $extraHours = (float)($slip['extra_hours'] ?? 0);
        $extraAmount = $extraHours * $otRate;
        $actualBase = (float)($slip['actual_base'] ?? $slip['base_salary'] ?? 0);
        $incentive = (float)($slip['incentive'] ?? 0);
        $allowance = (float)($slip['allowance'] ?? 0);
        $uangMakan = (float)($slip['uang_makan'] ?? 0);
        $bonus = (float)($slip['bonus'] ?? 0);
        $otherIncome = (float)($slip['other_income'] ?? 0);
        $totalDeductions = (float)($slip['total_deductions'] ?? 0);
        $totalEarnings = $actualBase + $otAmount + $extraAmount + $incentive + $allowance + $uangMakan + $bonus + $otherIncome;
        $netSalary = $totalEarnings - $totalDeductions;

        $slip['overtime_regular_amount'] = round($otAmount, 2);
        $slip['extra_hours'] = $extraHours;
        $slip['extra_overtime_amount'] = round($extraAmount, 2);
        $slip['overtime_total_hours'] = round($otHours + $extraHours, 2);
        $slip['overtime_total_amount'] = round($otAmount + $extraAmount, 2);
        $slip['total_earnings'] = round($totalEarnings, 2);
        $slip['net_salary'] = round($netSalary, 2);

        echo json_encode(['success' => true, 'data' => $slip]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat slip gaji']);
    }
    exit;
}

// ══════════════════════════════════════
// OVERTIME / LEMBUR REQUEST
// ══════════════════════════════════════
if ($action === 'overtime_submit') {
    $overtimeDate = trim($_POST['overtime_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if (!$overtimeDate) {
        echo json_encode(['success' => false, 'message' => 'Tanggal lembur wajib diisi']);
        exit;
    }
    if (!$reason) {
        echo json_encode(['success' => false, 'message' => 'Keterangan alasan lembur wajib diisi']);
        exit;
    }
    // Only allow today or past dates (can't request future overtime)
    if ($overtimeDate > date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'Tidak bisa mengajukan lembur untuk tanggal yang akan datang']);
        exit;
    }

    // Check duplicate
    $existing = $db->fetchOne("SELECT id, status FROM overtime_requests WHERE employee_id = ? AND overtime_date = ?", [$empId, $overtimeDate]);
    if ($existing) {
        $statusLabel = ['pending' => 'menunggu approval', 'approved' => 'sudah disetujui', 'rejected' => 'ditolak'];
        echo json_encode(['success' => false, 'message' => 'Sudah ada pengajuan lembur tanggal tersebut (status: ' . ($statusLabel[$existing['status']] ?? $existing['status']) . ')']);
        exit;
    }

    try {
        $pdo->prepare("INSERT INTO overtime_requests (employee_id, overtime_date, reason) VALUES (?, ?, ?)")
            ->execute([$empId, $overtimeDate, $reason]);

        // Push notification to admin/owner
        try {
            require_once __DIR__ . '/../../includes/PushNotificationHelper.php';
            $pushHelper = new PushNotificationHelper($db);
            $staffName = $_SESSION['staff_name'] ?? 'Staff';
            $pushHelper->sendToAdmins(
                "\xE2\x8F\xB0 Pengajuan Lembur: {$staffName}",
                "{$staffName} mengajukan lembur tanggal {$overtimeDate}",
                [
                    'type' => 'overtime_request',
                    'tag'  => 'overtime-' . $empId . '-' . time(),
                    'url'  => '/modules/payroll/employees.php'
                ]
            );
        } catch (\Throwable $pushErr) {
            error_log('Push notification error (overtime): ' . $pushErr->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Pengajuan lembur berhasil dikirim! Menunggu approval admin.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'overtime_history') {
    $month = trim($_GET['month'] ?? ''); // format YYYY-MM, kosong = semua
    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $rows = $db->fetchAll(
            "SELECT * FROM overtime_requests WHERE employee_id = ? AND DATE_FORMAT(overtime_date, '%Y-%m') = ? ORDER BY overtime_date DESC, created_at DESC LIMIT 100",
            [$empId, $month]
        ) ?: [];
        $stats = $db->fetchOne(
            "SELECT
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
             FROM overtime_requests WHERE employee_id = ? AND DATE_FORMAT(overtime_date, '%Y-%m') = ?",
            [$empId, $month]
        ) ?: [];
    } else {
        $rows = $db->fetchAll("SELECT * FROM overtime_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 50", [$empId]) ?: [];
        $stats = $db->fetchOne("SELECT
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
            FROM overtime_requests WHERE employee_id = ? AND YEAR(overtime_date) = YEAR(CURDATE())", [$empId]) ?: [];
    }
    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats, 'filter_month' => $month]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
