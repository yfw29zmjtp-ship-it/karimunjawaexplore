<?php

/**
 * FRONT DESK - HK ROOM ALLOCATION
 * Prioritas otomatis: B2B -> OD -> VD -> VC
 * Bisa input nama staff HK manual + override pembagian manual.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/HkAllocationHelper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Pembagian HK Room';
$message = '';
$error = '';

function parseStaffNames($raw)
{
    $parts = preg_split('/[\r\n,;]+/', (string)$raw);
    $names = [];
    foreach ($parts as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $name = preg_replace('/\s+/', ' ', $name);
        $name = mb_substr($name, 0, 100);
        $names[$name] = true;
    }
    return array_keys($names);
}

// Core allocation logic (ensureHkTables, buildHkTasks, autoAssignFair,
// syncHkStaffFromPayroll, getAttendanceEligibleHkStaff, hkGenerateAssignments,
// hkSyncDailyState) now lives in includes/HkAllocationHelper.php so the cron
// (api/hk-allocation-cron.php) can reuse the exact same behaviour.

ensureHkTables($db);

$workDate = $_POST['work_date'] ?? $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_staff') {
        try {
            $synced = syncHkStaffFromPayroll($db);
            $message = 'Sinkron staff HK dari data payroll berhasil: ' . count($synced) . ' staff aktif.';
        } catch (Exception $e) {
            if (method_exists($db, 'rollback')) {
                $db->rollback();
            }
            $error = 'Gagal sinkron staff HK dari payroll: ' . $e->getMessage();
        }
    }

    if ($action === 'reset_auto') {
        try {
            $db->query("DELETE FROM frontdesk_hk_assignments WHERE assignment_date = ?", [$workDate]);
            $message = 'Pembagian manual di-reset. Sistem kembali ke pembagian otomatis.';
        } catch (Exception $e) {
            $error = 'Gagal reset pembagian: ' . $e->getMessage();
        }
    }

    if ($action === 'save_plan' || $action === 'generate_auto') {
        try {
            syncHkStaffFromPayroll($db);
            $staffRowsNow = $db->fetchAll("SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC") ?: [];
            $staffNamesNow = array_map(fn($r) => $r['staff_name'], $staffRowsNow);

            if (empty($staffNamesNow)) {
                throw new Exception('Staff HK dari payroll kosong. Pastikan data payroll_employees (Housekeeping) sudah ada.');
            }

            if ($action === 'save_plan') {
                $tasksNow = buildHkTasks($db, $workDate);
                $teamAssigneeNow = 'TEAM';
                $assigneeOptionsNow = $staffNamesNow;
                if (!in_array($teamAssigneeNow, $assigneeOptionsNow, true)) {
                    $assigneeOptionsNow[] = $teamAssigneeNow;
                }

                $assignMap = [];
                $incoming = $_POST['assigned'] ?? [];
                foreach ($tasksNow as $task) {
                    $key = $task['key'];
                    $assigned = trim((string)($incoming[$key] ?? ''));
                    if ($assigned !== '' && in_array($assigned, $assigneeOptionsNow, true)) {
                        $assignMap[$key] = $assigned;
                    }
                }

                $db->beginTransaction();
                $db->query("DELETE FROM frontdesk_hk_assignments WHERE assignment_date = ?", [$workDate]);

                foreach ($tasksNow as $task) {
                    $key = $task['key'];
                    $assigned = $assignMap[$key] ?? '';
                    if ($assigned === '') {
                        continue;
                    }
                    $db->query(
                        "INSERT INTO frontdesk_hk_assignments
                        (assignment_date, room_id, room_number, task_code, priority_order, assigned_staff, is_manual, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
                        [
                            $workDate,
                            $task['room_id'],
                            $task['room_number'],
                            $task['task_code'],
                            $task['priority_order'],
                            $assigned,
                            $currentUser['id'] ?? null
                        ]
                    );
                }

                $db->commit();
                $message = 'Pembagian HK manual berhasil disimpan.';
            } else {
                // "Generate Ulang Otomatis" button - full regenerate using staff
                // who have already checked in (if past the 09:00 cutoff today).
                $attendanceNow = getAttendanceEligibleHkStaff($db, $staffNamesNow, $workDate, '09:00:00');
                $effectiveStaffNow = $attendanceNow['eligible_staff'];
                hkGenerateAssignments($db, $workDate, $effectiveStaffNow, $currentUser['id'] ?? null);
                $message = 'Pembagian HK otomatis berhasil dibuat ulang.';
            }
        } catch (Exception $e) {
            if (method_exists($db, 'rollback')) {
                $db->rollback();
            }
            $error = 'Gagal menyimpan pembagian: ' . $e->getMessage();
        }
    }
}

try {
    // Self-healing daily sync: generates the day's plan right after midnight
    // (using all staff, since attendance isn't relevant yet) if it hasn't been
    // generated already, and - once past the 09:00 attendance cutoff - moves
    // ONLY the rooms belonging to staff who haven't checked in over to staff
    // who have, leaving everyone else's room numbers untouched. This runs on
    // every page load AND from the api/hk-allocation-cron.php cron, so the
    // plan is always correct even if nobody opens this page.
    hkSyncDailyState($db, $workDate, $currentUser['id'] ?? null);
} catch (Exception $e) {
    if ($error === '') {
        $error = 'Gagal sinkron pembagian HK otomatis: ' . $e->getMessage();
    }
}

$staffRows = $db->fetchAll("SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC") ?: [];
$staffNames = array_map(fn($r) => $r['staff_name'], $staffRows);
$attendanceGate = getAttendanceEligibleHkStaff($db, $staffNames, $workDate, '09:00:00');
$effectiveStaffNames = $attendanceGate['eligible_staff'];
$absentStaffNames = $attendanceGate['absent_staff'];
$attendanceEnforced = (bool)($attendanceGate['enforced'] ?? false);
$teamAssignee = 'TEAM';
$displayAssignees = $staffNames;
if (!in_array($teamAssignee, $displayAssignees, true)) {
    $displayAssignees[] = $teamAssignee;
}
$staffText = implode("\n", $staffNames);

$tasks = buildHkTasks($db, $workDate);

$savedRows = $db->fetchAll(
    "SELECT room_id, task_code, assigned_staff, is_manual
     FROM frontdesk_hk_assignments
     WHERE assignment_date = ?",
    [$workDate]
) ?: [];

$savedMap = [];
foreach ($savedRows as $row) {
    $savedMap[(int)$row['room_id'] . '|' . $row['task_code']] = [
        'assigned_staff' => $row['assigned_staff'],
        'is_manual' => (int)$row['is_manual'] === 1
    ];
}

$assignmentMap = [];
$manualMap = [];
$seedCounts = array_fill_keys($displayAssignees, 0);

foreach ($tasks as $task) {
    $k = $task['key'];
    if (!isset($savedMap[$k])) {
        continue;
    }
    $assigned = $savedMap[$k]['assigned_staff'];
    if (!in_array($assigned, $displayAssignees, true)) {
        continue;
    }

    $isManualSaved = (int)$savedMap[$k]['is_manual'] === 1;
    if ($attendanceEnforced && !$isManualSaved && in_array($assigned, $absentStaffNames, true)) {
        // Auto assignment milik staff tidak hadir harus dialihkan ulang otomatis.
        continue;
    }

    $assignmentMap[$k] = $assigned;
    $manualMap[$k] = $isManualSaved;
    if (isset($seedCounts[$assigned])) {
        $seedCounts[$assigned]++;
    }
}

$unassignedTasks = array_values(array_filter($tasks, function ($t) use ($assignmentMap) {
    return !isset($assignmentMap[$t['key']]);
}));

$maxPerStaff = null;
if (count($effectiveStaffNames) > 0 && count($tasks) >= count($effectiveStaffNames)) {
    $maxPerStaff = intdiv(count($tasks), count($effectiveStaffNames));
}
$autoResult = autoAssignFair($unassignedTasks, $effectiveStaffNames, $seedCounts, $maxPerStaff, $teamAssignee);
foreach ($autoResult['assignments'] as $key => $staffName) {
    $assignmentMap[$key] = $staffName;
    $manualMap[$key] = false;
}

$staffLoad = array_fill_keys($displayAssignees, 0);
foreach ($tasks as $task) {
    $assigned = $assignmentMap[$task['key']] ?? '';
    if ($assigned !== '' && isset($staffLoad[$assigned])) {
        $staffLoad[$assigned]++;
    }
}

$categoryCount = ['B2B' => 0, 'OD' => 0, 'VD' => 0, 'VC' => 0];
foreach ($tasks as $task) {
    if (isset($categoryCount[$task['task_code']])) {
        $categoryCount[$task['task_code']]++;
    }
}

$tasksByStaff = [];
foreach ($displayAssignees as $sn) {
    $tasksByStaff[$sn] = [];
}

$unassignedTaskKeys = [];
foreach ($tasks as $task) {
    $taskKey = $task['key'];
    $assigned = $assignmentMap[$taskKey] ?? '';
    if ($assigned !== '' && isset($tasksByStaff[$assigned])) {
        $tasksByStaff[$assigned][] = $task;
    } else {
        $unassignedTaskKeys[] = $taskKey;
    }
}

include '../../includes/header.php';
?>

<style>
    .hk-wrap {
        max-width: 1240px;
        margin: 0 auto;
    }

    .hk-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
        margin-bottom: 0.75rem;
    }

    .hk-title {
        margin: 0;
        font-size: 1.12rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .hk-sub {
        font-size: 0.68rem;
        color: var(--text-muted);
        margin-top: 0.12rem;
    }

    .hk-grid {
        display: block;
    }

    .hk-top-row {
        display: grid;
        grid-template-columns: 1.25fr 1fr 1fr;
        gap: 0.55rem;
        margin-bottom: 0.6rem;
    }

    .hk-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 0.62rem;
    }

    .hk-card h3 {
        margin: 0 0 0.5rem 0;
        font-size: 0.78rem;
    }

    .hk-card-compact {
        min-height: 100%;
    }

    .hk-allocation-card {
        margin-top: 0;
    }

    .hk-input,
    .hk-select,
    .hk-date {
        width: 100%;
        border: 1px solid var(--bg-tertiary);
        border-radius: 7px;
        padding: 0.4rem 0.52rem;
        background: var(--bg-primary);
        color: var(--text-primary);
    }

    .hk-input {
        min-height: 72px;
        resize: vertical;
        font-size: 0.72rem;
        line-height: 1.2;
    }

    .hk-select,
    .hk-date {
        font-size: 0.72rem;
        min-height: 32px;
    }

    .hk-actions {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
    }

    .hk-btn {
        border: none;
        border-radius: 7px;
        padding: 0.34rem 0.54rem;
        font-weight: 700;
        font-size: 0.68rem;
        line-height: 1.2;
        cursor: pointer;
    }

    .hk-btn-primary {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
    }

    .hk-btn-secondary {
        background: #e2e8f0;
        color: #1e293b;
    }

    .hk-badges {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.42rem;
    }

    .hk-badge {
        border-radius: 8px;
        padding: 0.42rem;
        text-align: center;
        border: 1px solid transparent;
    }

    .b-b2b {
        background: #dcfce7;
        color: #166534;
        border-color: #86efac;
    }

    .b-od {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: #93c5fd;
    }

    .b-vd {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
    }

    .b-vc {
        background: #e2e8f0;
        color: #334155;
        border-color: #cbd5e1;
    }

    .hk-badge .n {
        font-size: 0.9rem;
        font-weight: 900;
        line-height: 1;
    }

    .hk-badge .l {
        font-size: 0.58rem;
        font-weight: 700;
        margin-top: 0.12rem;
    }

    .hk-staff-board {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.55rem;
    }

    .hk-staff-card {
        border: 1px solid var(--bg-tertiary);
        border-radius: 9px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.55), rgba(241, 245, 249, 0.4));
        overflow: hidden;
    }

    .hk-staff-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.38rem;
        padding: 0.48rem 0.58rem;
        border-bottom: 1px solid var(--bg-tertiary);
        background: rgba(226, 232, 240, 0.55);
    }

    .hk-staff-name {
        font-size: 0.76rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: 0.02em;
    }

    .hk-staff-count {
        display: inline-block;
        font-size: 0.58rem;
        font-weight: 800;
        border-radius: 20px;
        padding: 0.14rem 0.42rem;
        background: #dbeafe;
        color: #1d4ed8;
    }

    .hk-staff-body {
        padding: 0.5rem;
    }

    .hk-task-item {
        border: 1px solid #e2e8f0;
        border-left: 3px solid #64748b;
        border-radius: 8px;
        padding: 0.38rem 0.42rem;
        margin-bottom: 0.36rem;
        background: #ffffff;
    }

    .hk-task-item.b2b {
        border-left-color: #16a34a;
    }

    .hk-task-item.od {
        border-left-color: #2563eb;
    }

    .hk-task-item.vd {
        border-left-color: #d97706;
    }

    .hk-task-item.vc {
        border-left-color: #64748b;
    }

    .hk-task-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.35rem;
        margin-bottom: 0.18rem;
    }

    .hk-task-room {
        font-size: 0.7rem;
        font-weight: 800;
        color: #0f172a;
    }

    .hk-task-prio {
        font-size: 0.56rem;
        font-weight: 800;
        color: #475569;
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        padding: 0.1rem 0.36rem;
        border-radius: 999px;
    }

    .hk-task-context {
        font-size: 0.6rem;
        color: var(--text-muted);
        margin-bottom: 0.34rem;
    }

    .hk-task-footer {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.3rem;
        align-items: center;
    }

    .hk-pill {
        display: inline-block;
        font-size: 0.52rem;
        font-weight: 800;
        border-radius: 20px;
        padding: 0.12rem 0.36rem;
    }

    .hk-pill.manual {
        background: #fee2e2;
        color: #b91c1c;
    }

    .hk-pill.auto {
        background: #e0e7ff;
        color: #3730a3;
    }

    .hk-empty-staff {
        text-align: center;
        font-size: 0.66rem;
        color: var(--text-muted);
        padding: 0.55rem;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
    }

    .hk-room {
        font-weight: 800;
        color: #1e293b;
    }

    .hk-guest {
        font-size: 0.62rem;
        color: var(--text-muted);
        margin-top: 0.1rem;
    }

    .hk-staff-load {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.34rem;
        margin-top: 0.46rem;
    }

    .hk-load-item {
        border: 1px solid var(--bg-tertiary);
        border-radius: 7px;
        padding: 0.32rem;
        text-align: center;
    }

    .hk-load-item .name {
        font-size: 0.58rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .hk-load-item .num {
        font-size: 0.82rem;
        font-weight: 900;
        color: #0f172a;
        line-height: 1.1;
    }

    .alert {
        margin-bottom: 0.55rem;
        border-radius: 9px;
        padding: 0.48rem 0.6rem;
        font-size: 0.72rem;
        font-weight: 600;
    }

    .alert-ok {
        background: #ecfdf5;
        border: 1px solid #86efac;
        color: #166534;
    }

    .alert-err {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }

    @media (max-width: 980px) {
        .hk-top-row {
            grid-template-columns: 1fr;
        }

        .hk-staff-board {
            grid-template-columns: 1fr;
        }

        .hk-staff-load {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>

<div class="hk-wrap">
    <div class="hk-head">
        <div>
            <h1 class="hk-title">Pembagian Pembersihan Room HK</h1>
            <div class="hk-sub">Prioritas: B2B -> OD (In-House) -> VD -> VC. Staff HK sinkron dari Payroll, cutoff absensi 09:00 (hari ini) untuk redistribusi otomatis, sisa kamar masuk TEAM, lalu tetap bisa override manual.</div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="hk-grid">
        <div class="hk-top-row">
            <div class="hk-card hk-card-compact">
                <h3>Staff HK Aktif</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_staff">
                    <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
                    <label style="font-size:0.66rem;color:var(--text-muted);font-weight:700;display:block;margin-bottom:0.25rem;">Sumber nama: Payroll Employees (Department/Position Housekeeping)</label>
                    <textarea class="hk-input" name="staff_names" readonly><?php echo htmlspecialchars($staffText); ?></textarea>
                    <div class="hk-actions">
                        <button class="hk-btn hk-btn-primary" type="submit">Sinkron Ulang dari Payroll</button>
                    </div>
                </form>
            </div>

            <div class="hk-card hk-card-compact">
                <h3>Filter Tanggal Kerja</h3>
                <form method="get" class="hk-actions" style="margin-top:0;">
                    <input class="hk-date" type="date" name="date" value="<?php echo htmlspecialchars($workDate); ?>">
                    <button class="hk-btn hk-btn-secondary" type="submit">Muat Data</button>
                </form>
                <div class="hk-actions">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="generate_auto">
                        <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
                        <button class="hk-btn hk-btn-primary" type="submit">Generate Ulang Otomatis</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Reset manual assignment untuk tanggal ini?');">
                        <input type="hidden" name="action" value="reset_auto">
                        <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
                        <button class="hk-btn hk-btn-secondary" type="submit">Reset ke Auto</button>
                    </form>
                </div>
                <?php if ($attendanceEnforced): ?>
                    <div style="margin-top:0.5rem;font-size:0.64rem;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:7px;padding:0.38rem 0.46rem;">
                        Cutoff absensi 09:00 aktif untuk hari ini. Belum check-in sampai jam 09:00 dianggap tidak berangkat dan jatahnya dibagi ulang (pencocokan nama tidak sensitif huruf besar/kecil/spasi).<br>
                        <?php if (!empty($absentStaffNames)): ?>
                            Tidak hadir: <?php echo htmlspecialchars(implode(', ', $absentStaffNames)); ?>
                        <?php else: ?>
                            Semua staff HK hadir sebelum cutoff.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="hk-card hk-card-compact">
                <h3>Ringkasan Prioritas</h3>
                <div class="hk-badges">
                    <div class="hk-badge b-b2b">
                        <div class="n"><?php echo (int)$categoryCount['B2B']; ?></div>
                        <div class="l">B2B</div>
                    </div>
                    <div class="hk-badge b-od">
                        <div class="n"><?php echo (int)$categoryCount['OD']; ?></div>
                        <div class="l">OD</div>
                    </div>
                    <div class="hk-badge b-vd">
                        <div class="n"><?php echo (int)$categoryCount['VD']; ?></div>
                        <div class="l">VD</div>
                    </div>
                    <div class="hk-badge b-vc">
                        <div class="n"><?php echo (int)$categoryCount['VC']; ?></div>
                        <div class="l">VC</div>
                    </div>
                </div>

                <?php if (!empty($staffNames)): ?>
                    <div class="hk-staff-load">
                        <?php foreach ($displayAssignees as $sn): ?>
                            <div class="hk-load-item">
                                <div class="name"><?php echo htmlspecialchars($sn); ?></div>
                                <div class="num"><?php echo (int)($staffLoad[$sn] ?? 0); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="hk-card hk-allocation-card">
            <h3>Daftar Pembagian Kamar</h3>

            <?php if (empty($tasks)): ?>
                <div style="padding:1rem;color:var(--text-muted);">Tidak ada task kamar untuk tanggal ini.</div>
            <?php elseif (empty($staffNames)): ?>
                <div style="padding:1rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">Isi nama staff HK dulu agar pembagian otomatis bisa dihitung.</div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_plan">
                    <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">

                    <?php if (!empty($unassignedTaskKeys)): ?>
                        <div style="margin-bottom:0.5rem;font-size:0.67rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.42rem 0.52rem;">
                            Ada <?php echo count($unassignedTaskKeys); ?> task belum ter-assign. Pilih staff pada kartu task di bawah, lalu klik simpan.
                        </div>
                    <?php endif; ?>

                    <div class="hk-staff-board">
                        <?php foreach ($displayAssignees as $sn):
                            $staffTasks = $tasksByStaff[$sn] ?? [];
                            $isTeamCard = ($sn === $teamAssignee);
                            $isAbsentByCutoff = (!$isTeamCard && $attendanceEnforced && in_array($sn, $absentStaffNames, true));
                        ?>
                            <div class="hk-staff-card">
                                <div class="hk-staff-head">
                                    <div class="hk-staff-name">
                                        <?php echo htmlspecialchars($sn); ?>
                                        <?php if ($isAbsentByCutoff): ?>
                                            <span style="margin-left:6px;font-size:0.55rem;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:0.1rem 0.28rem;border-radius:999px;vertical-align:middle;">Belum Absen 09:00</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="hk-staff-count"><?php echo count($staffTasks); ?> Kamar</div>
                                </div>
                                <div class="hk-staff-body">
                                    <?php if (empty($staffTasks)): ?>
                                        <div class="hk-empty-staff">Belum ada jatah kamar.</div>
                                    <?php else: ?>
                                        <?php foreach ($staffTasks as $task):
                                            $key = $task['key'];
                                            $assigned = $assignmentMap[$key] ?? '';
                                            $isManual = (bool)($manualMap[$key] ?? false);
                                            $cls = strtolower($task['task_code']);
                                        ?>
                                            <div class="hk-task-item <?php echo htmlspecialchars($cls); ?>">
                                                <div class="hk-task-top">
                                                    <div class="hk-task-room">Room <?php echo htmlspecialchars($task['room_number']); ?> <span style="font-size:0.6rem;color:#64748b;font-weight:600;">(<?php echo htmlspecialchars($task['room_type']); ?>)</span></div>
                                                    <div class="hk-task-prio"><?php echo htmlspecialchars($task['task_code']); ?> • P<?php echo (int)$task['priority_order']; ?></div>
                                                </div>
                                                <div class="hk-task-context">
                                                    <?php if ($task['task_code'] === 'B2B'): ?>
                                                        In-house: <?php echo htmlspecialchars($task['inhouse_guest'] ?: '-'); ?> | Next: <?php echo htmlspecialchars($task['next_guest'] ?: '-'); ?>
                                                    <?php elseif ($task['task_code'] === 'OD'): ?>
                                                        Tamu in-house: <?php echo htmlspecialchars($task['inhouse_guest'] ?: '-'); ?>
                                                    <?php elseif ($task['task_code'] === 'VC' && !empty($task['next_guest'])): ?>
                                                        Arrival hari ini: <?php echo htmlspecialchars($task['next_guest']); ?> | Status: <?php echo htmlspecialchars(strtoupper($task['room_status'])); ?>
                                                    <?php else: ?>
                                                        Status room: <?php echo htmlspecialchars(strtoupper($task['room_status'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="hk-task-footer">
                                                    <select class="hk-select" name="assigned[<?php echo htmlspecialchars($key); ?>]">
                                                        <option value="">- Pilih Staff -</option>
                                                        <?php foreach ($displayAssignees as $snOption): ?>
                                                            <option value="<?php echo htmlspecialchars($snOption); ?>" <?php echo $assigned === $snOption ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($snOption); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($isManual): ?>
                                                        <span class="hk-pill manual">Manual</span>
                                                    <?php else: ?>
                                                        <span class="hk-pill auto">Auto</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="hk-actions" style="margin-top:1rem;justify-content:flex-end;">
                        <button class="hk-btn hk-btn-primary" type="submit">Simpan Pembagian Manual</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>