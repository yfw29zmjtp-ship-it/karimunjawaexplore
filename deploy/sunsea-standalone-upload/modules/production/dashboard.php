<?php
/**
 * Production Module - Dashboard
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

// Stats
$stats = [];
try {
    $stats['total_so']       = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders")->fetchColumn();
    $stats['pending_so']     = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status IN ('quotation','confirmed')")->fetchColumn();
    $stats['total_po']       = (int)$pdo->query("SELECT COUNT(*) FROM production_orders")->fetchColumn();
    $stats['in_production']  = (int)$pdo->query("SELECT COUNT(*) FROM production_orders WHERE status = 'in_production'")->fetchColumn();
    $stats['low_stock']      = (int)$pdo->query("SELECT COUNT(*) FROM production_materials WHERE stock_quantity <= min_stock AND min_stock > 0")->fetchColumn();
    $stats['today_schedule'] = (int)$pdo->query("SELECT COUNT(*) FROM workshop_schedule WHERE scheduled_date = CURDATE() AND status != 'cancelled'")->fetchColumn();
    $stats['revenue_month']  = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE status NOT IN ('cancelled') AND MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())")->fetchColumn();
} catch (Exception $e) {
    $stats = array_fill_keys(['total_so','pending_so','total_po','in_production','low_stock','today_schedule','revenue_month'], 0);
}

// Recent Sales Orders
$recentOrders = [];
try {
    $recentOrders = $pdo->query("SELECT * FROM sales_orders ORDER BY created_at DESC LIMIT 5")->fetchAll();
} catch (Exception $e) {}

// Today's schedule
$todaySchedule = [];
try {
    $todaySchedule = $pdo->query("
        SELECT ws.*, po.product_name, po.order_number as po_number
        FROM workshop_schedule ws
        LEFT JOIN production_orders po ON ws.production_order_id = po.id
        WHERE ws.scheduled_date = CURDATE() AND ws.status != 'cancelled'
        ORDER BY ws.start_time ASC
    ")->fetchAll();
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-1"><i class="bi bi-tools me-2 text-primary"></i>Dashboard Produksi</h4>
            <p class="text-muted mb-0">Ringkasan aktivitas produksi PWF Furniture</p>
        </div>
        <div class="d-flex gap-2">
            <a href="sales-orders.php?action=add" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Sales Order Baru</a>
            <a href="orders.php?action=add" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Order Produksi</a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="content-card text-center h-100">
            <div class="fs-2 fw-bold text-success"><?= $stats['total_so'] ?></div>
            <div class="text-muted small">Total Sales Order</div>
            <div class="mt-1"><span class="badge bg-warning text-dark"><?= $stats['pending_so'] ?> pending</span></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="content-card text-center h-100">
            <div class="fs-2 fw-bold text-primary"><?= $stats['total_po'] ?></div>
            <div class="text-muted small">Order Produksi</div>
            <div class="mt-1"><span class="badge bg-primary"><?= $stats['in_production'] ?> berjalan</span></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="content-card text-center h-100">
            <div class="fs-2 fw-bold <?= $stats['low_stock'] > 0 ? 'text-danger' : 'text-success' ?>"><?= $stats['low_stock'] ?></div>
            <div class="text-muted small">Bahan Baku Menipis</div>
            <div class="mt-1"><a href="materials.php?filter=low_stock" class="text-decoration-none"><small>Lihat stok →</small></a></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="content-card text-center h-100">
            <div class="fs-2 fw-bold text-info"><?= $stats['today_schedule'] ?></div>
            <div class="text-muted small">Jadwal Hari Ini</div>
            <div class="mt-1"><a href="schedule.php" class="text-decoration-none"><small>Lihat jadwal →</small></a></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent Sales Orders -->
    <div class="col-md-7">
        <div class="content-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-semibold">Sales Order Terbaru</h6>
                <a href="sales-orders.php" class="btn btn-sm btn-outline-secondary">Lihat Semua</a>
            </div>
            <?php if (empty($recentOrders)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada sales order.
                    <br><a href="sales-orders.php?action=add">Buat sekarang</a>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>No.</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentOrders as $so): ?>
                    <tr>
                        <td><a href="sales-orders.php?id=<?= $so['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($so['order_number']) ?></a></td>
                        <td><?= htmlspecialchars($so['customer_name']) ?></td>
                        <td class="text-end">Rp <?= number_format($so['total_amount'],0,',','.') ?></td>
                        <td><?php
                            $badges = ['quotation'=>'secondary','confirmed'=>'info','in_production'=>'primary','ready'=>'warning','delivered'=>'success','cancelled'=>'danger'];
                            $labels = ['quotation'=>'Penawaran','confirmed'=>'Dikonfirmasi','in_production'=>'Produksi','ready'=>'Siap','delivered'=>'Terkirim','cancelled'=>'Batal'];
                            $s = $so['status'];
                            echo '<span class="badge bg-'.($badges[$s]??'secondary').'">'.($labels[$s]??$s).'</span>';
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Today's Schedule -->
    <div class="col-md-5">
        <div class="content-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-semibold">Jadwal Workshop Hari Ini</h6>
                <a href="schedule.php" class="btn btn-sm btn-outline-secondary">Kelola</a>
            </div>
            <?php if (empty($todaySchedule)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>Tidak ada jadwal hari ini.
                </div>
            <?php else: ?>
            <?php foreach ($todaySchedule as $sch): ?>
            <div class="d-flex align-items-start gap-2 mb-3 p-2 rounded bg-light">
                <div class="text-center" style="min-width:45px">
                    <div class="text-muted" style="font-size:0.7rem"><?= $sch['start_time'] ? substr($sch['start_time'],0,5) : '--:--' ?></div>
                    <div class="text-muted" style="font-size:0.7rem"><?= $sch['end_time'] ? substr($sch['end_time'],0,5) : '' ?></div>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small"><?= htmlspecialchars($sch['team_member']) ?></div>
                    <div class="text-muted" style="font-size:0.8rem"><?= htmlspecialchars(substr($sch['task_description'],0,50)) ?></div>
                    <?php if ($sch['po_number']): ?><span class="badge bg-light text-dark border" style="font-size:0.7rem"><?= htmlspecialchars($sch['po_number']) ?></span><?php endif; ?>
                </div>
                <span class="badge bg-<?= ['scheduled'=>'secondary','in_progress'=>'primary','completed'=>'success'][$sch['status']]??'secondary' ?>">
                    <?= ['scheduled'=>'Terjadwal','in_progress'=>'Berjalan','completed'=>'Selesai'][$sch['status']]??$sch['status'] ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
