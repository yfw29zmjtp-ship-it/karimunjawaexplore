<?php
/**
 * Production Module - Workshop Schedule
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

try {
    $pdo = getProductionPdo();
} catch (Exception $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$error   = '';
$success = '';

$statusList = ['scheduled'=>'Terjadwal','in_progress'=>'Berjalan','completed'=>'Selesai','cancelled'=>'Dibatalkan'];

// ============================
// HANDLE POST
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';

    if ($fa === 'add' || $fa === 'edit') {
        $teamMember   = trim($_POST['team_member'] ?? '');
        $task         = trim($_POST['task_description'] ?? '');
        $date         = $_POST['scheduled_date'] ?? '';
        $start        = $_POST['start_time'] ?? null;
        $end          = $_POST['end_time'] ?? null;
        $status       = $_POST['status'] ?? 'scheduled';
        $notes        = trim($_POST['notes'] ?? '');
        $poId         = (int)($_POST['production_order_id'] ?? 0) ?: null;

        if (empty($teamMember) || empty($task) || empty($date)) {
            $error = 'Nama anggota, deskripsi tugas, dan tanggal wajib diisi.';
        } else {
            try {
                if ($fa === 'add') {
                    $pdo->prepare("INSERT INTO workshop_schedule (production_order_id, team_member, task_description, scheduled_date, start_time, end_time, status, notes) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$poId, $teamMember, $task, $date, $start ?: null, $end ?: null, $status, $notes]);
                    $success = 'Jadwal berhasil ditambahkan.';
                } else {
                    $pdo->prepare("UPDATE workshop_schedule SET production_order_id=?, team_member=?, task_description=?, scheduled_date=?, start_time=?, end_time=?, status=?, notes=?, updated_at=NOW() WHERE id=?")
                        ->execute([$poId, $teamMember, $task, $date, $start ?: null, $end ?: null, $status, $notes, $id]);
                    $success = 'Jadwal berhasil diperbarui.';
                }
                $action = 'list';
            } catch (Exception $e) {
                $error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }

    if ($fa === 'delete') {
        try {
            $pdo->prepare("DELETE FROM workshop_schedule WHERE id=?")->execute([$id]);
            $success = 'Jadwal dihapus.';
        } catch (Exception $e) {
            $error = 'Gagal menghapus.';
        }
        $action = 'list';
    }

    if ($fa === 'status') {
        $newStatus = $_POST['new_status'] ?? '';
        if (array_key_exists($newStatus, $statusList)) {
            $pdo->prepare("UPDATE workshop_schedule SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $id]);
        }
        $action = 'list';
    }
}

// ============================
// DATA
// ============================
$viewDate = $_GET['date'] ?? date('Y-m-d');
$schedules = [];
if ($action === 'list') {
    $stmt = $pdo->prepare("
        SELECT ws.*, po.product_name, po.order_number as po_number
        FROM workshop_schedule ws
        LEFT JOIN production_orders po ON ws.production_order_id = po.id
        WHERE ws.scheduled_date = ?
        ORDER BY ws.start_time ASC, ws.team_member ASC
    ");
    $stmt->execute([$viewDate]);
    $schedules = $stmt->fetchAll();
}

$editSchedule = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM workshop_schedule WHERE id=?");
    $stmt->execute([$id]);
    $editSchedule = $stmt->fetch();
}

$productionOrders = $pdo->query("SELECT id, order_number, product_name FROM production_orders WHERE status NOT IN ('completed','cancelled') ORDER BY order_number DESC")->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-1"><i class="bi bi-calendar-week me-2 text-success"></i>Workshop Schedule</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="dashboard.php">Produksi</a></li>
                <li class="breadcrumb-item active">Jadwal Workshop</li>
            </ol></nav>
        </div>
        <?php if ($action === 'list'): ?>
        <a href="?action=add&date=<?= $viewDate ?>" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Tambah Jadwal</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="content-card" style="max-width:650px">
    <h5 class="mb-4"><?= $action === 'add' ? 'Jadwal Baru' : 'Edit Jadwal' ?></h5>
    <form method="POST">
        <input type="hidden" name="form_action" value="<?= $action ?>">
        <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nama Tukang / Tim <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="team_member" required
                       placeholder="Nama tukang atau tim" value="<?= htmlspecialchars($editSchedule['team_member'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Tanggal <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="scheduled_date" required
                       value="<?= $editSchedule['scheduled_date'] ?? ($_GET['date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Jam Mulai</label>
                <input type="time" class="form-control" name="start_time" value="<?= $editSchedule['start_time'] ?? '08:00' ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Jam Selesai</label>
                <input type="time" class="form-control" name="end_time" value="<?= $editSchedule['end_time'] ?? '17:00' ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Deskripsi Tugas <span class="text-danger">*</span></label>
                <textarea class="form-control" name="task_description" rows="2" required placeholder="Proses/tugas yang dikerjakan"><?= htmlspecialchars($editSchedule['task_description'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Terhubung ke Order Produksi</label>
                <select class="form-select" name="production_order_id">
                    <option value="">- Tidak ada -</option>
                    <?php foreach ($productionOrders as $po): ?>
                    <option value="<?= $po['id'] ?>" <?= ($editSchedule['production_order_id'] ?? '') == $po['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($po['order_number'] . ' - ' . $po['product_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <?php foreach ($statusList as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($editSchedule['status'] ?? 'scheduled') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($editSchedule['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Simpan</button>
            <a href="schedule.php" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Date Navigator -->
<div class="content-card mb-3">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <?php
        $prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
        $nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));
        ?>
        <a href="?date=<?= $prevDate ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="date" class="form-control form-control-sm" name="date" value="<?= $viewDate ?>" onchange="this.form.submit()">
            <span class="text-muted fw-semibold"><?= date('l, d F Y', strtotime($viewDate)) ?></span>
            <?php if ($viewDate !== date('Y-m-d')): ?>
            <a href="schedule.php" class="btn btn-sm btn-outline-primary">Hari Ini</a>
            <?php endif; ?>
        </form>
        <a href="?date=<?= $nextDate ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<div class="content-card">
    <?php if (empty($schedules)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>Tidak ada jadwal pada tanggal ini.
        <br><a href="?action=add&date=<?= $viewDate ?>">Tambah jadwal</a>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($schedules as $sch): ?>
        <?php $statusColor = ['scheduled'=>'secondary','in_progress'=>'primary','completed'=>'success','cancelled'=>'danger']; ?>
        <div class="col-md-6">
            <div class="card border-start border-<?= $statusColor[$sch['status']]??'secondary' ?> border-3">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($sch['team_member']) ?></div>
                            <div class="text-muted small">
                                <?= $sch['start_time'] ? substr($sch['start_time'],0,5) : '--:--' ?>
                                <?= $sch['end_time'] ? ' – ' . substr($sch['end_time'],0,5) : '' ?>
                            </div>
                        </div>
                        <span class="badge bg-<?= $statusColor[$sch['status']]??'secondary' ?>"><?= $statusList[$sch['status']]??$sch['status'] ?></span>
                    </div>
                    <p class="mt-2 mb-1 small"><?= htmlspecialchars($sch['task_description']) ?></p>
                    <?php if ($sch['po_number']): ?>
                    <span class="badge bg-light text-dark border" style="font-size:0.75rem"><i class="bi bi-hammer me-1"></i><?= htmlspecialchars($sch['po_number']) ?> – <?= htmlspecialchars($sch['product_name']) ?></span>
                    <?php endif; ?>
                    <div class="d-flex gap-1 mt-2">
                        <?php if ($sch['status'] === 'scheduled'): ?>
                        <form method="POST"><input type="hidden" name="form_action" value="status"><input type="hidden" name="id" value="<?= $sch['id'] ?>"><input type="hidden" name="new_status" value="in_progress"><button class="btn btn-xs btn-outline-primary" style="font-size:0.75rem;padding:2px 8px">▶ Mulai</button></form>
                        <?php elseif ($sch['status'] === 'in_progress'): ?>
                        <form method="POST"><input type="hidden" name="form_action" value="status"><input type="hidden" name="id" value="<?= $sch['id'] ?>"><input type="hidden" name="new_status" value="completed"><button class="btn btn-xs btn-outline-success" style="font-size:0.75rem;padding:2px 8px">✓ Selesai</button></form>
                        <?php endif; ?>
                        <a href="?action=edit&id=<?= $sch['id'] ?>&date=<?= $viewDate ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:2px 8px"><i class="bi bi-pencil"></i></a>
                        <form method="POST" onsubmit="return confirm('Hapus jadwal ini?')"><input type="hidden" name="form_action" value="delete"><input type="hidden" name="id" value="<?= $sch['id'] ?>"><button class="btn btn-xs btn-outline-danger" style="font-size:0.75rem;padding:2px 8px"><i class="bi bi-trash"></i></button></form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
