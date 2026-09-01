<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $project_id = intval($_POST['project_id'] ?? 0);
    $worker_id = intval($_POST['worker_id'] ?? 0);
    $period_type = $_POST['period_type'] ?? 'weekly';
    $period_label = trim($_POST['period_label'] ?? '');
    $daily_rate = floatval($_POST['daily_rate'] ?? 0);
    $overtime_per_day = floatval($_POST['overtime_per_day'] ?? 0);
    $other_per_day = floatval($_POST['other_per_day'] ?? 0);
    $total_days = intval($_POST['total_days'] ?? 0);
    $total_salary = floatval($_POST['total_salary'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!$project_id || !$worker_id || $total_days < 1) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        exit;
    }

    // Recalculate server-side for safety
    $total_salary = ($daily_rate + $overtime_per_day + $other_per_day) * $total_days;

    $stmt = $db->prepare("INSERT INTO project_salaries 
        (project_id, worker_id, period_type, period_label, daily_rate, overtime_per_day, other_per_day, total_days, total_salary, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$project_id, $worker_id, $period_type, $period_label, $daily_rate, $overtime_per_day, $other_per_day, $total_days, $total_salary, $notes]);

    echo json_encode(['success' => true, 'message' => 'Gaji berhasil dicatat', 'total_salary' => $total_salary]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
