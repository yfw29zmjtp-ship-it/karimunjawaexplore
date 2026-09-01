<?php
// modules/payroll/weekly-payroll-save.php - API Save & Sync Cashbook
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$action = $input['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

try {
    if ($action === 'save') {
        $empId = (int)($input['employee_id'] ?? 0);
        $month = (int)($input['period_month'] ?? 0);
        $year = (int)($input['period_year'] ?? 0);
        $w1 = max(0, (float)($input['week_1'] ?? 0));
        $w2 = max(0, (float)($input['week_2'] ?? 0));
        $w3 = max(0, (float)($input['week_3'] ?? 0));
        $w4 = max(0, (float)($input['week_4'] ?? 0));
        $notes = trim($input['notes'] ?? '');
        $total = $w1 + $w2 + $w3 + $w4;

        if (!$empId || !$month || !$year) {
            throw new Exception('Data tidak lengkap');
        }

        // Get employee info
        $emp = $db->fetchOne("SELECT full_name, position, department FROM payroll_employees WHERE id = ?", [$empId]);
        if (!$emp) throw new Exception('Karyawan tidak ditemukan');

        // Upsert
        $existing = $db->fetchOne("SELECT id, cashbook_synced FROM payroll_weekly WHERE employee_id = ? AND period_month = ? AND period_year = ?", [$empId, $month, $year]);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE payroll_weekly SET 
                week_1=?, week_2=?, week_3=?, week_4=?, total_salary=?, notes=?,
                employee_name=?, position=?, department=?, updated_at=NOW()
                WHERE id=?");
            $stmt->execute([$w1, $w2, $w3, $w4, $total, $notes, $emp['full_name'], $emp['position'], $emp['department'], $existing['id']]);
            
            // If already synced to cashbook and amounts changed, reset sync flag
            if ($existing['cashbook_synced']) {
                $pdo->prepare("UPDATE payroll_weekly SET cashbook_synced = 0 WHERE id = ?")->execute([$existing['id']]);
            }
            
            echo json_encode(['success' => true, 'message' => "Gaji {$emp['full_name']} disimpan", 'id' => $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO payroll_weekly 
                (employee_id, employee_name, position, department, period_month, period_year,
                 week_1, week_2, week_3, week_4, total_salary, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$empId, $emp['full_name'], $emp['position'], $emp['department'], $month, $year, $w1, $w2, $w3, $w4, $total, $notes, $userId]);
            $newId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'message' => "Gaji {$emp['full_name']} disimpan", 'id' => $newId]);
        }

    } elseif ($action === 'sync_cashbook') {
        $empId = (int)($input['employee_id'] ?? 0);
        $month = (int)($input['period_month'] ?? 0);
        $year = (int)($input['period_year'] ?? 0);

        $rec = $db->fetchOne("SELECT * FROM payroll_weekly WHERE employee_id = ? AND period_month = ? AND period_year = ?", [$empId, $month, $year]);
        if (!$rec) throw new Exception('Data gaji belum ada. Simpan dulu.');
        if ($rec['total_salary'] <= 0) throw new Exception('Total gaji 0, tidak bisa masuk kas.');
        if ($rec['cashbook_synced']) throw new Exception('Sudah masuk buku kas sebelumnya.');

        $monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        $periodLabel = $monthNames[$month] . ' ' . $year;

        // Find or create "Gaji" division
        $gajiDiv = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%gaji%' AND is_active = 1 LIMIT 1");
        if (!$gajiDiv) {
            $pdo->exec("INSERT INTO divisions (division_name, division_type, is_active) VALUES ('Gaji', 'expense', 1)");
            $gajiDivId = $pdo->lastInsertId();
        } else {
            $gajiDivId = $gajiDiv['id'];
        }

        // Find or create "Gaji Mingguan" category
        $gajiCat = $db->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%gaji mingguan%' AND category_type = 'expense' LIMIT 1");
        if (!$gajiCat) {
            $pdo->prepare("INSERT INTO categories (division_id, category_name, category_type, is_active) VALUES (?, 'Gaji Mingguan', 'expense', 1)")->execute([$gajiDivId]);
            $gajiCatId = $pdo->lastInsertId();
        } else {
            $gajiCatId = $gajiCat['id'];
        }

        // Insert into cash_book
        $desc = "Gaji Mingguan {$rec['employee_name']} - {$periodLabel}";
        if ($rec['position']) $desc .= " ({$rec['position']})";

        $cbData = [
            'transaction_date' => date('Y-m-d'),
            'transaction_time' => date('H:i:s'),
            'division_id' => $gajiDivId,
            'category_id' => $gajiCatId,
            'category_name' => 'Gaji Mingguan',
            'transaction_type' => 'expense',
            'amount' => $rec['total_salary'],
            'description' => $desc,
            'payment_method' => 'cash',
            'created_by' => $userId,
            'source_type' => 'weekly_payroll',
            'source_id' => $rec['id'],
            'reference_no' => 'WP-' . $rec['id'],
            'is_editable' => 0
        ];

        $cbId = $db->insert('cash_book', $cbData);

        // Mark as synced
        $pdo->prepare("UPDATE payroll_weekly SET cashbook_synced = 1, status = 'paid' WHERE id = ?")->execute([$rec['id']]);

        echo json_encode([
            'success' => true,
            'message' => "Gaji {$rec['employee_name']} Rp " . number_format($rec['total_salary'], 0, ',', '.') . " masuk ke Buku Kas",
            'cashbook_id' => $cbId
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
} catch (Exception $e) {
    error_log("Weekly Payroll Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
