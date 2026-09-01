<?php
// modules/payroll/employees.php - MODERN 2027 DESIGN
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    include '../../includes/header.php';
    echo '<div class="main-content"><div class="alert alert-warning">Modul Gaji (Payroll) belum diaktifkan.</div></div>';
    include '../../includes/footer.php';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Auto-add finger_id column if missing
try {
    $db->query("SELECT finger_id FROM payroll_employees LIMIT 1");
} catch (Exception $e) {
    $db->getConnection()->exec("ALTER TABLE payroll_employees ADD COLUMN `finger_id` VARCHAR(20) DEFAULT NULL");
}

// Auto-create tables if missing
$check = $db->query("SHOW TABLES LIKE 'payroll_employees'");
if ($check->rowCount() === 0) {
    $sqlFile = __DIR__ . '/../../database-payroll.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $pdo = $db->getConnection();
        // Strip comment lines, then split and execute
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                }
            }
        }
    }
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

$pageTitle = 'Employee Data';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $employee_code = $_POST['employee_code'] ?? 'EMP-' . time();
        $full_name = trim($_POST['full_name']);
        $position = trim($_POST['position']);
        $department = trim($_POST['department']);
        $phone = trim($_POST['phone']);
        $join_date = $_POST['join_date'];
        // Bersihkan: ambil hanya digit, buang desimal & pemisah ribuan
        $rawSalary = (string)($_POST['base_salary'] ?? '0');
        if (strpos($rawSalary, ',') !== false) {
            $rawSalary = explode(',', $rawSalary)[0];
        }
        if (substr_count($rawSalary, '.') >= 1 && preg_match('/\.[0-9]{1,2}$/', $rawSalary)) {
            // Format "5000000.00" -> ambil bagian sebelum titik terakhir bila itu desimal
            $parts = explode('.', $rawSalary);
            $last = end($parts);
            if (strlen($last) <= 2) {
                array_pop($parts);
                $rawSalary = implode('', $parts);
            }
        }
        $base_salary = (int) preg_replace('/\D/', '', $rawSalary);
        $bank_name = $_POST['bank_name'];
        $bank_account = $_POST['bank_account'];
        $finger_id = trim($_POST['finger_id'] ?? '');

        try {
            if ($action === 'create') {
                if (empty($employee_code)) {
                    $count = $db->fetchOne("SELECT COUNT(*) as c FROM payroll_employees");
                    $employee_code = 'EMP-' . str_pad($count['c'] + 1, 3, '0', STR_PAD_LEFT);
                }
                $sql = "INSERT INTO payroll_employees (employee_code, full_name, position, department, phone, join_date, base_salary, bank_name, bank_account, finger_id, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db->query($sql, [$employee_code, $full_name, $position, $department, $phone, $join_date, $base_salary, $bank_name, $bank_account, $finger_id ?: null, $_SESSION['user_id']]);
                setFlash('success', 'Karyawan berhasil ditambahkan');
            } else {
                $sql = "UPDATE payroll_employees SET full_name=?, position=?, department=?, phone=?, join_date=?, base_salary=?, bank_name=?, bank_account=?, finger_id=? WHERE id=?";
                $db->query($sql, [$full_name, $position, $department, $phone, $join_date, $base_salary, $bank_name, $bank_account, $finger_id ?: null, $id]);

                // Sinkronkan base_salary ke semua slip payroll yg masih bisa diedit
                // (hours_locked=0) di periode bulan berjalan & masa depan.
                // Periode bulan lalu (frozen) tidak diubah.
                try {
                    $curMonth = (int) date('n');
                    $curYear  = (int) date('Y');
                    $db->query(
                        "UPDATE payroll_slips s
                         INNER JOIN payroll_periods p ON p.id = s.period_id
                         SET s.base_salary = ?
                         WHERE s.employee_id = ?
                           AND s.hours_locked = 0
                           AND (p.period_year > ? OR (p.period_year = ? AND p.period_month >= ?))",
                        [$base_salary, $id, $curYear, $curYear, $curMonth]
                    );
                } catch (Exception $e) { /* kolom belum ada / table belum dibuat */
                }

                setFlash('success', 'Data karyawan berhasil diperbarui (gaji pokok tersinkron ke Process Salary)');
            }
        } catch (PDOException $e) {
            setFlash('error', 'Gagal menyimpan: ' . $e->getMessage());
        }
        header('Location: employees.php');
        exit;
    }

    if ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $db->query("UPDATE payroll_employees SET is_active = 0 WHERE id = ?", [$id]);
            setFlash('success', 'Employee deactivated');
        } catch (Exception $e) {
            setFlash('error', 'Failed to delete: ' . $e->getMessage());
        }
        header('Location: employees.php');
        exit;
    }
}

$employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1 ORDER BY full_name ASC");
$totalBase = array_sum(array_column($employees, 'base_salary'));
$avgSalary = count($employees) > 0 ? $totalBase / count($employees) : 0;
$deptCounts = [];
foreach ($employees as $e) {
    $d = $e['department'] ?: 'Unassigned';
    $deptCounts[$d] = ($deptCounts[$d] ?? 0) + 1;
}
$totalDepts = count($deptCounts);

include '../../includes/header.php';
?>

<style>
    /* ══════════════════════════════════════════════════════════════════════════
   EMPLOYEES 2027 - ELEGANT WIDE DESIGN
   ══════════════════════════════════════════════════════════════════════════ */

    :root {
        --pr-gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --pr-gradient-2: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --pr-gradient-3: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --pr-shadow-soft: 0 8px 32px rgba(0, 0, 0, 0.08);
        --pr-shadow-card: 0 4px 24px rgba(0, 0, 0, 0.06);
        --pr-radius: 16px;
        --pr-radius-sm: 12px;
    }

    /* Header Hero - Wider & More Prominent */
    .emp-header {
        background: var(--pr-gradient-1);
        border-radius: var(--pr-radius);
        padding: 2rem 2.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        position: relative;
        overflow: hidden;
    }

    .emp-header::before {
        content: '';
        position: absolute;
        top: -60%;
        right: -5%;
        width: 350px;
        height: 350px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.12) 0%, transparent 70%);
        border-radius: 50%;
    }

    .emp-header::after {
        content: '';
        position: absolute;
        bottom: -40%;
        left: 10%;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.07) 0%, transparent 70%);
        border-radius: 50%;
    }

    .emp-header h1 {
        color: #fff;
        font-size: 1.65rem;
        font-weight: 800;
        margin: 0;
        position: relative;
        z-index: 2;
        letter-spacing: -0.3px;
    }

    .emp-header p {
        color: rgba(255, 255, 255, 0.85);
        margin: 0.25rem 0 0;
        font-size: 0.95rem;
        font-weight: 400;
    }

    .btn-add-emp {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: #fff;
        padding: 0.75rem 1.5rem;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        position: relative;
        z-index: 2;
    }

    .btn-add-emp:hover {
        background: rgba(255, 255, 255, 0.35);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    }

    /* Stats - 4 cards in a row */
    .emp-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .emp-stat-item {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--pr-radius-sm);
        padding: 1.25rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: all 0.2s;
        box-shadow: var(--pr-shadow-card);
    }

    .emp-stat-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.1);
    }

    .emp-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .emp-stat-icon svg {
        width: 22px;
        height: 22px;
    }

    .emp-stat-icon.purple {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.15), rgba(118, 75, 162, 0.15));
        color: #667eea;
    }

    .emp-stat-icon.green {
        background: linear-gradient(135deg, rgba(17, 153, 142, 0.15), rgba(56, 239, 125, 0.15));
        color: #11998e;
    }

    .emp-stat-icon.blue {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(37, 99, 235, 0.15));
        color: #3b82f6;
    }

    .emp-stat-icon.orange {
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(234, 88, 12, 0.15));
        color: #f59e0b;
    }

    .emp-stat-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: var(--text-tertiary);
        margin-bottom: 0.2rem;
        font-weight: 500;
    }

    .emp-stat-value {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--text-primary);
        margin: 0;
        letter-spacing: -0.5px;
    }

    /* Table Card */
    .emp-table-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--pr-radius);
        overflow: hidden;
        box-shadow: var(--pr-shadow-card);
    }

    .emp-table-header {
        padding: 1.25rem 1.75rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .emp-table-header h3 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
        color: var(--text-primary);
    }

    .emp-search {
        position: relative;
        width: 300px;
    }

    .emp-search input {
        width: 100%;
        padding: 0.7rem 1rem 0.7rem 2.75rem;
        border: 1px solid var(--border-color);
        border-radius: 50px;
        font-size: 0.9rem;
        background: var(--bg-secondary);
        transition: all 0.2s;
    }

    .emp-search input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .emp-search svg {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        width: 16px;
        height: 16px;
        color: var(--text-tertiary);
    }

    /* Employee Table - Spacious */
    .emp-table {
        width: 100%;
        border-collapse: collapse;
    }

    .emp-table th {
        padding: 0.85rem 1.25rem;
        text-align: left;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        color: var(--text-tertiary);
        background: var(--bg-secondary);
        border-bottom: 2px solid var(--border-color);
        white-space: nowrap;
    }

    .emp-table td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-light, rgba(0, 0, 0, 0.04));
        vertical-align: middle;
        font-size: 0.9rem;
    }

    .emp-table tr {
        transition: all 0.15s;
    }

    .emp-table tr:hover td {
        background: rgba(102, 126, 234, 0.03);
    }

    .emp-table tr:last-child td {
        border-bottom: none;
    }

    /* Employee Info Cell - Bigger avatar */
    .emp-info {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-width: 200px;
    }

    .emp-avatar {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: var(--pr-gradient-1);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 700;
        font-size: 0.85rem;
        flex-shrink: 0;
        letter-spacing: 0.5px;
    }

    .emp-name {
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.15rem;
        font-size: 0.95rem;
    }

    .emp-code {
        font-size: 0.78rem;
        color: var(--text-tertiary);
        font-weight: 500;
    }

    /* Position & Department */
    .emp-position {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .emp-dept {
        font-size: 0.78rem;
        color: var(--text-tertiary);
        margin-top: 0.1rem;
        font-weight: 500;
    }

    /* Salary - Prominent */
    .emp-salary {
        font-weight: 800;
        font-size: 0.95rem;
        background: var(--pr-gradient-2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        white-space: nowrap;
    }

    /* Bank Info */
    .emp-bank {
        font-size: 0.85rem;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .emp-bank span {
        display: block;
        font-family: 'SF Mono', 'Fira Code', monospace;
        color: var(--text-tertiary);
        font-size: 0.78rem;
        margin-top: 0.1rem;
        letter-spacing: 0.3px;
    }

    /* Badge Styles */
    .emp-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.3rem 0.65rem;
        border-radius: 6px;
        font-size: 0.78rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .emp-badge-finger {
        background: #eff6ff;
        color: #1e40af;
    }

    .emp-badge-face {
        background: #f0fdf4;
        color: #166534;
    }

    .emp-badge-empty {
        color: #94a3b8;
        font-weight: 400;
        font-size: 0.82rem;
    }

    /* Actions */
    .emp-actions {
        display: flex;
        gap: 0.4rem;
    }

    .emp-btn-action {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        border: 1px solid transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        background: transparent;
    }

    .emp-btn-action.edit {
        color: #667eea;
    }

    .emp-btn-action.edit:hover {
        background: rgba(102, 126, 234, 0.1);
        border-color: rgba(102, 126, 234, 0.2);
    }

    .emp-btn-action.delete {
        color: #dc3545;
    }

    .emp-btn-action.delete:hover {
        background: rgba(220, 53, 69, 0.08);
        border-color: rgba(220, 53, 69, 0.2);
    }

    .emp-btn-action:hover {
        transform: scale(1.05);
    }

    /* Empty State */
    .emp-empty {
        text-align: center;
        padding: 3.5rem 1.5rem;
    }

    .emp-empty-icon {
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

    /* Modal - Luxe Style */
    .emp-modal-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 1000;
        display: none;
    }

    .emp-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.95);
        background: var(--bg-primary);
        border-radius: var(--pr-radius);
        width: 95%;
        max-width: 580px;
        max-height: 90vh;
        overflow: auto;
        z-index: 1001;
        display: none;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .emp-modal.show {
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
    }

    .emp-modal-header {
        padding: 1.5rem 1.75rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .emp-modal-header h3 {
        font-size: 1.2rem;
        font-weight: 800;
        margin: 0;
        background: var(--pr-gradient-1);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .emp-modal-close {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        border: none;
        background: var(--bg-secondary);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-secondary);
        transition: all 0.2s;
    }

    .emp-modal-close:hover {
        background: #dc3545;
        color: #fff;
    }

    .emp-modal-body {
        padding: 1.75rem;
    }

    .emp-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.1rem;
        margin-bottom: 1.1rem;
    }

    .emp-form-group {
        margin-bottom: 1.1rem;
    }

    .emp-form-group:last-child {
        margin-bottom: 0;
    }

    .emp-form-label {
        display: block;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.45rem;
    }

    .emp-form-label.required::after {
        content: '*';
        color: #dc3545;
        margin-left: 0.25rem;
    }

    .emp-form-input {
        width: 100%;
        padding: 0.7rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 0.92rem;
        background: var(--bg-primary);
        transition: all 0.2s;
        color: var(--text-primary);
    }

    .emp-form-input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .emp-form-note {
        font-size: 0.76rem;
        color: var(--text-tertiary);
        margin-top: 0.4rem;
    }

    .emp-salary-input {
        display: flex;
        align-items: center;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        overflow: hidden;
        transition: all 0.2s;
    }

    .emp-salary-input:focus-within {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .emp-salary-prefix {
        padding: 0.7rem 1rem;
        background: var(--bg-secondary);
        font-weight: 700;
        color: var(--text-secondary);
        font-size: 0.9rem;
        border-right: 1px solid var(--border-color);
    }

    .emp-salary-input input {
        flex: 1;
        border: none;
        padding: 0.7rem 1rem;
        font-size: 0.92rem;
        background: transparent;
        color: var(--text-primary);
    }

    .emp-salary-input input:focus {
        outline: none;
    }

    .emp-modal-footer {
        padding: 1.25rem 1.75rem;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 0.85rem;
    }

    .emp-btn-cancel {
        padding: 0.7rem 1.5rem;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: transparent;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.92rem;
        color: var(--text-secondary);
    }

    .emp-btn-cancel:hover {
        background: var(--bg-secondary);
    }

    .emp-btn-save {
        padding: 0.7rem 1.75rem;
        border-radius: 10px;
        border: none;
        background: var(--pr-gradient-1);
        color: #fff;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.92rem;
    }

    .emp-btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(102, 126, 234, 0.35);
    }

    /* Page Wrapper - Full width */
    .emp-page-wrapper {
        max-width: 100%;
        margin: 0 auto;
        padding: 0;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .emp-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .emp-stats {
            grid-template-columns: 1fr;
        }

        .emp-form-row {
            grid-template-columns: 1fr;
        }

        .emp-table-header {
            flex-direction: column;
            align-items: stretch;
        }

        .emp-search {
            width: 100%;
        }

        .emp-header {
            padding: 1.5rem;
        }

        .emp-header h1 {
            font-size: 1.3rem;
        }
    }
</style>

<div class="emp-page-wrapper">

    <!-- Header -->
    <div class="emp-header fade-in-up">
        <div>
            <h1>Employee Data</h1>
            <p>Manage employee database for payroll</p>
        </div>
        <button class="btn-add-emp" onclick="openModal('create')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            Add Employee
        </button>
    </div>

    <!-- Stats -->
    <div class="emp-stats fade-in-up" style="animation-delay: 0.1s">
        <div class="emp-stat-item">
            <div class="emp-stat-icon purple">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div>
                <p class="emp-stat-label">Total Employees</p>
                <h3 class="emp-stat-value"><?php echo count($employees); ?></h3>
            </div>
        </div>
        <div class="emp-stat-item">
            <div class="emp-stat-icon green">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                    <line x1="1" y1="10" x2="23" y2="10"></line>
                </svg>
            </div>
            <div>
                <p class="emp-stat-label">Total Base Salary</p>
                <h3 class="emp-stat-value">Rp <?php echo number_format($totalBase, 0, ',', '.'); ?></h3>
            </div>
        </div>
        <div class="emp-stat-item">
            <div class="emp-stat-icon blue">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <div>
                <p class="emp-stat-label">Average Salary</p>
                <h3 class="emp-stat-value">Rp <?php echo number_format($avgSalary, 0, ',', '.'); ?></h3>
            </div>
        </div>
        <div class="emp-stat-item">
            <div class="emp-stat-icon orange">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>
            <div>
                <p class="emp-stat-label">Departments</p>
                <h3 class="emp-stat-value"><?php echo $totalDepts; ?></h3>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="emp-table-card fade-in-up" style="animation-delay: 0.2s">
        <div class="emp-table-header">
            <h3>Active Employees</h3>
            <div class="emp-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="searchInput" placeholder="Search name or position..." onkeyup="filterTable()">
            </div>
        </div>

        <?php if (empty($employees)): ?>
            <div class="emp-empty">
                <div class="emp-empty-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                    </svg>
                </div>
                <h4 style="margin: 0 0 0.5rem; color: var(--text-secondary);">No Employees Yet</h4>
                <p style="margin: 0; color: var(--text-tertiary);">Start by adding your first employee</p>
            </div>
        <?php else: ?>
            <table class="emp-table" id="employeeTable">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Position</th>
                        <th>Join Date</th>
                        <th>Base Salary</th>
                        <th>Bank Account</th>
                        <th style="text-align:center;">Biometrics</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp):
                        $initials = strtoupper(substr($emp['full_name'], 0, 1)) . strtoupper(substr(strrchr($emp['full_name'], ' ') ?: $emp['full_name'], 1, 1));
                    ?>
                        <tr>
                            <td>
                                <div class="emp-info">
                                    <div class="emp-avatar"><?php echo $initials; ?></div>
                                    <div>
                                        <div class="emp-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                                        <div class="emp-code"><?php echo $emp['employee_code']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="emp-position"><?php echo htmlspecialchars($emp['position']); ?></div>
                                <div class="emp-dept"><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></div>
                            </td>
                            <td><?php echo date('d M Y', strtotime($emp['join_date'])); ?></td>
                            <td><span class="emp-salary">Rp <?php echo number_format($emp['base_salary'], 0, ',', '.'); ?></span></td>
                            <td>
                                <div class="emp-bank">
                                    <?php echo htmlspecialchars($emp['bank_name'] ?: '-'); ?>
                                    <span><?php echo htmlspecialchars($emp['bank_account'] ?: '-'); ?></span>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <div style="display:flex; flex-direction:column; align-items:center; gap:0.3rem;">
                                    <?php if (!empty($emp['finger_id'])): ?>
                                        <span class="emp-badge emp-badge-finger">🔒 PIN <?php echo htmlspecialchars($emp['finger_id']); ?></span>
                                    <?php else: ?>
                                        <span class="emp-badge-empty">— No PIN</span>
                                    <?php endif; ?>
                                    <?php if (!empty($emp['face_descriptor'])): ?>
                                        <span class="emp-badge emp-badge-face">👁️ Face</span>
                                    <?php else: ?>
                                        <span class="emp-badge-empty">— No Face</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="emp-actions" style="justify-content: center;">
                                    <button class="emp-btn-action edit" title="Edit" onclick='editEmployee(<?php echo json_encode($emp); ?>)'>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg>
                                    </button>
                                    <button class="emp-btn-action delete" title="Delete" onclick="deleteEmployee(<?php echo $emp['id']; ?>)">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="emp-modal-backdrop" id="modalBackdrop"></div>
<div class="emp-modal" id="employeeModal">
    <form method="POST" id="employeeForm">
        <div class="emp-modal-header">
            <h3 id="modalTitle">Add Employee</h3>
            <button type="button" class="emp-modal-close" onclick="closeModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="emp-modal-body">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="employeeId">

            <div class="emp-form-row">
                <div class="emp-form-group">
                    <label class="emp-form-label required">Full Name</label>
                    <input type="text" name="full_name" id="fullName" class="emp-form-input" required>
                </div>
                <div class="emp-form-group">
                    <label class="emp-form-label">Employee Code</label>
                    <input type="text" name="employee_code" id="employeeCode" class="emp-form-input" placeholder="Auto" readonly style="background: var(--bg-secondary);">
                </div>
            </div>

            <div class="emp-form-row">
                <div class="emp-form-group">
                    <label class="emp-form-label required">Position</label>
                    <input type="text" name="position" id="position" class="emp-form-input" required list="positions">
                    <datalist id="positions">
                        <option value="Manager">
                        <option value="Supervisor">
                        <option value="Chef">
                        <option value="Cook Helper">
                        <option value="Waiter">
                        <option value="Cashier">
                        <option value="Housekeeping">
                        <option value="Front Desk">
                    </datalist>
                </div>
                <div class="emp-form-group">
                    <label class="emp-form-label">Department</label>
                    <select name="department" id="department" class="emp-form-input">
                        <option value="">- Select -</option>
                        <option value="Kitchen">Kitchen</option>
                        <option value="Service">Service</option>
                        <option value="Front Office">Front Office</option>
                        <option value="Admin">Admin</option>
                        <option value="Housekeeping">Housekeeping</option>
                    </select>
                </div>
            </div>

            <div class="emp-form-row">
                <div class="emp-form-group">
                    <label class="emp-form-label required">Join Date</label>
                    <input type="date" name="join_date" id="joinDate" class="emp-form-input" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="emp-form-group">
                    <label class="emp-form-label">Phone Number</label>
                    <input type="text" name="phone" id="phone" class="emp-form-input">
                </div>
            </div>

            <div class="emp-form-group">
                <label class="emp-form-label required">Base Salary</label>
                <div class="emp-salary-input">
                    <span class="emp-salary-prefix">Rp</span>
                    <input type="text" name="base_salary" id="baseSalary" required onkeyup="formatCurrency(this)">
                </div>
                <p class="emp-form-note">Overtime calculation: Base Salary ÷ 200 = Rate per hour</p>
            </div>

            <div class="emp-form-row">
                <div class="emp-form-group">
                    <label class="emp-form-label">Bank Name</label>
                    <input type="text" name="bank_name" id="bankName" class="emp-form-input" list="banks">
                    <datalist id="banks">
                        <option value="BCA">
                        <option value="BRI">
                        <option value="BNI">
                        <option value="Mandiri">
                    </datalist>
                </div>
                <div class="emp-form-group">
                    <label class="emp-form-label">Account Number</label>
                    <input type="text" name="bank_account" id="bankAccount" class="emp-form-input">
                </div>
            </div>

            <div class="emp-form-group">
                <label class="emp-form-label">🔒 Finger ID (PIN Mesin Fingerprint)</label>
                <input type="text" name="finger_id" id="fingerId" class="emp-form-input" placeholder="Nomor PIN di mesin absensi (opsional)">
                <p class="emp-form-note">Masukkan PIN karyawan yang terdaftar di mesin fingerprint Revo / Fingerspot. Kosongkan jika belum pakai fingerprint.</p>
            </div>
        </div>
        <div class="emp-modal-footer">
            <button type="button" class="emp-btn-cancel" onclick="closeModal()">Cancel</button>
            <button type="submit" class="emp-btn-save">Save Data</button>
        </div>
    </form>
</div>

<script>
    function openModal(mode) {
        document.getElementById('modalBackdrop').style.display = 'block';
        const modal = document.getElementById('employeeModal');
        modal.style.display = 'block';
        setTimeout(() => modal.classList.add('show'), 10);

        if (mode === 'create') {
            document.getElementById('modalTitle').innerText = 'Add Employee';
            document.getElementById('formAction').value = 'create';
            document.getElementById('employeeForm').reset();
            document.getElementById('joinDate').valueAsDate = new Date();
        }
    }

    function closeModal() {
        const modal = document.getElementById('employeeModal');
        modal.classList.remove('show');
        setTimeout(() => {
            document.getElementById('modalBackdrop').style.display = 'none';
            modal.style.display = 'none';
        }, 300);
    }

    function formatCurrency(input) {
        // Hilangkan desimal di belakang koma/titik (mis. "5000000.00" -> "5000000")
        let raw = String(input.value || '').trim();
        if (raw.includes(',')) raw = raw.split(',')[0];
        // Jika ada "." dan dua digit terakhir setelah titik -> anggap desimal, buang
        if (/\.[0-9]{1,2}$/.test(raw)) {
            const parts = raw.split('.');
            const last = parts[parts.length - 1];
            if (last.length <= 2) {
                parts.pop();
                raw = parts.join('');
            }
        }
        const digits = raw.replace(/\D/g, '');
        input.value = digits === '' ? '' : new Intl.NumberFormat('id-ID').format(parseInt(digits, 10));
    }

    function editEmployee(data) {
        openModal('edit');
        document.getElementById('modalTitle').innerText = 'Edit Employee';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('employeeId').value = data.id;
        document.getElementById('employeeCode').value = data.employee_code;
        document.getElementById('fullName').value = data.full_name;
        document.getElementById('position').value = data.position;
        document.getElementById('department').value = data.department;
        document.getElementById('joinDate').value = data.join_date;
        document.getElementById('phone').value = data.phone;
        // base_salary dari DB bisa berupa "5000000.00" — paksa jadi integer
        document.getElementById('baseSalary').value = String(Math.floor(parseFloat(data.base_salary) || 0));
        formatCurrency(document.getElementById('baseSalary'));
        document.getElementById('bankName').value = data.bank_name;
        document.getElementById('bankAccount').value = data.bank_account;
        document.getElementById('fingerId').value = data.finger_id || '';
    }

    function deleteEmployee(id) {
        if (confirm('Are you sure you want to deactivate this employee?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    function filterTable() {
        const filter = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#employeeTable tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    }

    // Close modal on backdrop click
    document.getElementById('modalBackdrop').addEventListener('click', closeModal);
</script>

<?php include '../../includes/footer.php'; ?>