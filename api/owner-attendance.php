<?php

/**
 * API: Owner Attendance Monitoring
 * Fetch attendance data for a specific date
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Auth check
$role = $_SESSION['role'] ?? null;
$isLoggedIn = $_SESSION['logged_in'] ?? false;
if (!$isLoggedIn || !in_array($role, ['owner', 'admin', 'super_admin', 'developer', 'manager'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Validate date parameter
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

// Get business DB connection
$businessId = (int)($_GET['business_id'] ?? $_SESSION['business_id'] ?? 0);
if (!$businessId) {
    echo json_encode(['success' => false, 'error' => 'No business selected']);
    exit;
}

try {
    $masterDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $masterDb->prepare("SELECT db_name FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    $biz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$biz || !$biz['db_name']) {
        echo json_encode(['success' => false, 'error' => 'Business not found']);
        exit;
    }

    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $biz['db_name'], DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all active employees
    $stmt = $pdo->query("SELECT id, employee_code, full_name, position, department FROM payroll_employees WHERE is_active = 1 ORDER BY full_name");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEmployees = count($employees);

    // Get attendance records for date
    $stmt = $pdo->prepare("
        SELECT a.*, e.full_name, e.employee_code, e.position, e.department
        FROM payroll_attendance a
        JOIN payroll_employees e ON e.id = a.employee_id
        WHERE a.attendance_date = ?
        ORDER BY e.full_name
    ");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate stats
    $stats = ['total' => $totalEmployees, 'present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0, 'recorded' => count($records)];
    $recordedIds = [];
    foreach ($records as $r) {
        $recordedIds[] = $r['employee_id'];
        if ($r['status'] === 'late') $stats['late']++;
        elseif ($r['status'] === 'leave' || $r['status'] === 'holiday') $stats['leave']++;
        else $stats['present']++;
    }
    $stats['absent'] = $totalEmployees - count($recordedIds);

    // Get absent employees
    $absent = [];
    foreach ($employees as $emp) {
        if (!in_array($emp['id'], $recordedIds)) {
            $absent[] = ['full_name' => $emp['full_name'], 'position' => $emp['position']];
        }
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'stats' => $stats,
        'records' => $records,
        'absent' => $absent
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
