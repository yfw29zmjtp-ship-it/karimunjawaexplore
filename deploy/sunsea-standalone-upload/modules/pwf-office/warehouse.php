<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$msg     = '';
$msgType = 'success';

try {
    $pdo = getPwfOfficePdo();
} catch (\Throwable $e) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<div style="padding:20px;color:red"><h2>Warehouse DB Error</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre></div>';
    echo '</body></html>';
    exit;
}

// ── MIGRATE: extend source ENUM ──────────────────────────────────────────────
try {
    $pdo->exec("ALTER TABLE pwf_warehouse_stock MODIFY COLUMN source ENUM('manual','from_order','from_order_failed') DEFAULT 'manual'");
} catch (\PDOException $e) {
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['_action'] ?? 'create';
    $orderId = ((int)($_POST['order_id'] ?? 0)) ?: null;

    if ($action === 'create') {
        if (!pwfUserHasAccess('pwf_warehouse', 'create')) {
            $msgType = 'warning';
            $msg = 'Anda tidak memiliki akses untuk menambah stock.';
        } else {
        $productName = trim($_POST['product_name'] ?? '');
        $quantity    = (float)($_POST['quantity']     ?? 0);
        if ($productName !== '' && $quantity > 0) {
            try {
                $code = genPwfCode($pdo, 'STK');
                $pdo->prepare(
                    'INSERT INTO pwf_warehouse_stock
                        (stock_code, product_name, quantity, unit, finish, wood_color,
                         dimensions, specification, notes, source, order_id, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $code,
                    $productName,
                    $quantity,
                    trim($_POST['unit']         ?? 'pcs'),
                    trim($_POST['finish']        ?? ''),
                    trim($_POST['wood_color']    ?? ''),
                    trim($_POST['dimensions']    ?? ''),
                    trim($_POST['specification'] ?? ''),
                    trim($_POST['notes']         ?? ''),
                    $orderId ? 'from_order' : 'manual',
                    $orderId,
                    $_SESSION['user_id'] ?? null,
                ]);
                $msg = 'Stock added successfully.';
            } catch (\Throwable $e) {
                error_log('PWF warehouse create: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Failed to add: ' . $e->getMessage();
            }
        } else {
            $msgType = 'warning';
            $msg = 'Product name and quantity are required.';
        }
        }
    } elseif ($action === 'update') {
        if (!pwfUserHasAccess('pwf_warehouse', 'edit')) {
            $msgType = 'warning';
            $msg = 'Anda tidak memiliki akses untuk mengubah stock.';
        } else {
        $id = (int)($_POST['stock_id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare(
                    'UPDATE pwf_warehouse_stock SET
                        product_name=?, quantity=?, unit=?, finish=?, wood_color=?,
                        dimensions=?, specification=?, notes=?, order_id=?, updated_at=NOW()
                     WHERE id=?'
                )->execute([
                    trim($_POST['product_name']  ?? ''),
                    (float)($_POST['quantity']    ?? 0),
                    trim($_POST['unit']           ?? 'pcs'),
                    trim($_POST['finish']         ?? ''),
                    trim($_POST['wood_color']     ?? ''),
                    trim($_POST['dimensions']     ?? ''),
                    trim($_POST['specification']  ?? ''),
                    trim($_POST['notes']          ?? ''),
                    $orderId,
                    $id,
                ]);
                $msg = 'Stock updated.';
            } catch (\Throwable $e) {
                error_log('PWF warehouse update: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Failed to update: ' . $e->getMessage();
            }
        }
        }
    } elseif ($action === 'delete') {
        if (!pwfUserHasAccess('pwf_warehouse', 'delete')) {
            $msgType = 'warning';
            $msg = 'Anda tidak memiliki akses untuk menghapus stock.';
        } else {
        $id = (int)($_POST['stock_id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare('DELETE FROM pwf_warehouse_stock WHERE id=?')->execute([$id]);
                $msg = 'Stock deleted.';
            } catch (\Throwable $e) {
                error_log('PWF warehouse delete: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Failed to delete: ' . $e->getMessage();
            }
        }
        }
    }
}

$canCreateStock = pwfUserHasAccess('pwf_warehouse', 'create');
$canEditStock   = pwfUserHasAccess('pwf_warehouse', 'edit');
$canDeleteStock = pwfUserHasAccess('pwf_warehouse', 'delete');

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filterSearch    = trim($_GET['search'] ?? '');
$filterContainer = trim($_GET['filter'] ?? ''); // '' | 'in_container' | 'no_container'

$whereParts = [];
$whereArgs  = [];

if ($filterSearch !== '') {
    $whereParts[] = '(ws.product_name LIKE ? OR ws.stock_code LIKE ? OR c.customer_name LIKE ?)';
    $whereArgs[]  = '%' . $filterSearch . '%';
    $whereArgs[]  = '%' . $filterSearch . '%';
    $whereArgs[]  = '%' . $filterSearch . '%';
}
if ($filterContainer === 'in_container') {
    $whereParts[] = 'ws.order_id IN (SELECT DISTINCT order_id FROM pwf_container_items WHERE order_id IS NOT NULL)';
} elseif ($filterContainer === 'no_container') {
    $whereParts[] = '(ws.order_id IS NULL OR ws.order_id NOT IN (SELECT DISTINCT order_id FROM pwf_container_items WHERE order_id IS NOT NULL))';
}
$whereClause = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// ── DATA ──────────────────────────────────────────────────────────────────────
$stocks        = [];
$summary       = ['total_items' => 0, 'unique_products' => 0, 'total_qty' => 0, 'in_container' => 0];
$ordersForLink = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            ws.*,
            o.order_code,
            o.status        AS order_status,
            o.order_date,
            o.due_date,
            o.product_name  AS o_product_name,
            o.quantity      AS o_quantity,
            o.unit          AS o_unit,
            o.unit_price,
            o.total_price,
            o.wood_color    AS o_wood_color,
            o.finish        AS o_finish,
            o.dimensions    AS o_dimensions,
            o.specification AS o_specification,
            o.notes         AS o_notes,
            c.customer_name,
            MIN(con.id)                  AS container_db_id,
            MIN(con.container_code)      AS container_code,
            MIN(con.container_no)        AS container_no,
            MIN(con.container_type)      AS container_type,
            MIN(con.shipment_date)       AS shipment_date,
            MIN(con.status)              AS container_status,
            MIN(con.destination_country) AS destination_country,
            MIN(con.destination_port)    AS destination_port,
            SUM(ci.qty_shipped)          AS qty_shipped
        FROM pwf_warehouse_stock ws
        LEFT JOIN pwf_orders o           ON o.id      = ws.order_id
        LEFT JOIN pwf_customers c        ON c.id      = o.customer_id
        LEFT JOIN pwf_container_items ci ON ci.order_id = ws.order_id
        LEFT JOIN pwf_containers con     ON con.id    = ci.container_id
        $whereClause
        GROUP BY ws.id, o.order_code, o.status, o.order_date, o.due_date,
                 o.product_name, o.quantity, o.unit, o.unit_price, o.total_price,
                 o.wood_color, o.finish, o.dimensions, o.specification, o.notes,
                 c.customer_name
        ORDER BY ws.created_at DESC
    ");
    $stmt->execute($whereArgs);
    $stocks = $stmt->fetchAll();

    $sumRow = $pdo->query("
        SELECT
            COUNT(DISTINCT ws.id)           AS total_items,
            COUNT(DISTINCT ws.product_name) AS unique_products,
            COALESCE(SUM(ws.quantity), 0)   AS total_qty,
            COUNT(DISTINCT CASE WHEN ci.container_id IS NOT NULL THEN ws.id END) AS in_container
        FROM pwf_warehouse_stock ws
        LEFT JOIN pwf_container_items ci ON ci.order_id = ws.order_id
    ")->fetch();
    if ($sumRow) $summary = $sumRow;

    $ordersForLink = $pdo->query("
        SELECT o.id, o.order_code, o.product_name, o.status,
               COALESCE(c.customer_name, 'Unknown') AS customer_name
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id = o.customer_id
        WHERE o.status NOT IN ('cancelled')
        ORDER BY o.order_code DESC
        LIMIT 300
    ")->fetchAll();
} catch (\Throwable $e) {
    if (empty($msg)) {
        $msg = 'Database error: ' . $e->getMessage();
        $msgType = 'warning';
    }
    $ordersForLink = [];
}

// JS-safe id-keyed data
$stocksForJs = [];
foreach ($stocks as $s) {
    $stocksForJs[(int)$s['id']] = $s;
}

$orderStatusLabel = [
    'draft'        => ['Draft',            '#94a3b8'],
    'on_progress'  => ['In Progress',      '#f59e0b'],
    'qc'           => ['QC / Inspection',  '#8b5cf6'],
    'ready_ship'   => ['Ready to Ship',    '#10b981'],
    'partial_ship' => ['Partial Ship',     '#0ea5e9'],
    'shipped'      => ['Shipped',          '#6366f1'],
    'completed'    => ['Completed',        '#059669'],
    'cancelled'    => ['Cancelled',        '#ef4444'],
];
$containerStatusLabel = [
    'draft'   => ['Draft',    '#94a3b8'],
    'booked'  => ['Booked',   '#f59e0b'],
    'onboard' => ['On Board', '#0ea5e9'],
    'arrived' => ['Arrived',  '#10b981'],
    'closed'  => ['Closed',   '#6366f1'],
];

pwfOfficeHeader('Warehouse / Stock', 'warehouse');
?>

<style>
    /* ── CRITICAL: modal overlay hidden by default ───────────────────────── */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .45);
        backdrop-filter: blur(2px);
        z-index: 9000;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.open {
        display: flex;
    }

    .modal-box {
        background: var(--card);
        border-radius: 16px;
        width: min(760px, 96vw);
        max-height: 92vh;
        overflow-y: auto;
        box-shadow: 0 24px 80px rgba(0, 0, 0, .25);
    }

    .modal-header {
        padding: 16px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        background: var(--card);
        z-index: 1;
    }

    .modal-body {
        padding: 18px 22px;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 22px;
        cursor: pointer;
        color: var(--muted);
        line-height: 1;
        padding: 0;
    }

    .modal-close:hover {
        color: var(--text);
    }

    /* ── Layout ────────────────────────────────────────────────────────── */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 12px 16px;
        background: var(--nav-hover);
        border-bottom: 1px solid var(--border);
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px;
        padding: 16px;
        background: #fcfdff;
        border-bottom: 1px solid var(--border);
    }

    .stat-card {
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 10px;
        padding: 14px;
        text-align: center;
    }

    .stat-label {
        color: var(--muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 20px;
        font-weight: 800;
        color: var(--gold);
    }

    .stock-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(295px, 1fr));
        gap: 14px;
        padding: 16px;
    }

    .stock-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 14px;
        transition: box-shadow .2s, transform .15s;
    }

    .stock-card:hover {
        box-shadow: 0 6px 24px rgba(0, 0, 0, .09);
        transform: translateY(-2px);
    }

    .stock-code {
        font-family: monospace;
        font-size: 11px;
        color: var(--gold);
        font-weight: 700;
    }

    .stock-name {
        font-size: 15px;
        font-weight: 800;
        margin: 4px 0 6px;
        color: var(--text);
    }

    .stock-meta {
        font-size: 12px;
        color: var(--muted);
        margin: 4px 0;
    }

    .stock-footer {
        display: flex;
        gap: 6px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid var(--border);
    }

    .stock-footer .btn {
        flex: 1;
        padding: 6px 8px;
        font-size: 11px;
    }

    /* ── Badges ─────────────────────────────────────────────────────────── */
    .stk-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
    }

    .badge-blue {
        background: #dbeafe;
        color: #1e40af;
    }

    .badge-gray {
        background: #f1f5f9;
        color: #64748b;
    }

    .badge-purple {
        background: #ede9fe;
        color: #5b21b6;
    }

    /* ── Detail modal ───────────────────────────────────────────────────── */
    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px 20px;
        font-size: 13px;
    }

    .detail-grid .full {
        grid-column: 1 / -1;
    }

    .det-label {
        font-size: 10px;
        color: var(--muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .3px;
        margin-bottom: 2px;
    }

    .det-value {
        font-size: 13px;
        color: var(--text);
        font-weight: 500;
    }

    .det-section {
        background: var(--nav-hover);
        border-radius: 8px;
        padding: 12px;
        margin-top: 12px;
    }

    .det-section-title {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: var(--gold);
        margin-bottom: 10px;
    }

    /* ── Filter chips ───────────────────────────────────────────────────── */
    .filter-chip {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        border: 1px solid var(--border);
        background: var(--card);
        color: var(--muted);
        cursor: pointer;
        text-decoration: none;
        transition: all .15s;
    }

    .filter-chip:hover {
        border-color: var(--gold);
        color: var(--gold);
    }

    .filter-chip.active {
        background: var(--gold);
        color: #fff;
        border-color: var(--gold);
    }

    .name-clickable {
        cursor: pointer;
        color: var(--gold) !important;
    }

    .name-clickable:hover {
        text-decoration: underline;
    }

    @media print {
        .no-print {
            display: none;
        }
    }
</style>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'warning' ?>" style="margin-bottom:16px">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- ═══ FILTER BAR ═══════════════════════════════════════════════════════ -->
<div class="pwf-card no-print" style="margin-bottom:16px">
    <div class="filter-bar">
        <form method="get" style="display:contents">
            <input type="text" name="search" placeholder="Cari produk, kode, customer..."
                value="<?= htmlspecialchars($filterSearch) ?>" class="input" style="flex:1;min-width:200px">
            <?php if ($filterContainer): ?>
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filterContainer) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-outline-secondary" style="gap:6px">
                <i class="bi bi-search"></i> Cari
            </button>
            <?php if ($filterSearch): ?>
                <a href="warehouse.php<?= $filterContainer ? '?filter=' . urlencode($filterContainer) : '' ?>"
                    class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i> Clear</a>
            <?php endif; ?>
        </form>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <a href="warehouse.php<?= $filterSearch ? '?search=' . urlencode($filterSearch) : '' ?>"
                class="filter-chip <?= $filterContainer === '' ? 'active' : '' ?>">All</a>
            <a href="warehouse.php?filter=in_container<?= $filterSearch ? '&search=' . urlencode($filterSearch) : '' ?>"
                class="filter-chip <?= $filterContainer === 'in_container' ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i> In Container</a>
            <a href="warehouse.php?filter=no_container<?= $filterSearch ? '&search=' . urlencode($filterSearch) : '' ?>"
                class="filter-chip <?= $filterContainer === 'no_container' ? 'active' : '' ?>">
                <i class="bi bi-clock"></i> Belum Container</a>
        </div>
        <div style="margin-left:auto">
            <?php if ($canCreateStock): ?>
            <button class="btn btn-sm" id="btnAddStock" style="gap:6px">
                <i class="bi bi-plus-lg"></i> Add Stock
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Total Items</div>
            <div class="stat-value"><?= (int)($summary['total_items'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unique Products</div>
            <div class="stat-value"><?= (int)($summary['unique_products'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Qty</div>
            <div class="stat-value"><?= rtrim(rtrim(number_format((float)($summary['total_qty'] ?? 0), 2), '0'), '.') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">In Container</div>
            <div class="stat-value" style="color:#0ea5e9"><?= (int)($summary['in_container'] ?? 0) ?></div>
        </div>
    </div>
</div>

<!-- ═══ STOCK GRID ═══════════════════════════════════════════════════════ -->
<div class="pwf-card">
    <?php if (empty($stocks)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--muted)">
            <i class="bi bi-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:.4"></i>
            No stock items found.
        </div>
    <?php else: ?>
        <div class="stock-grid">
            <?php foreach ($stocks as $s):
                $hasOrder     = !empty($s['order_id']);
                $hasContainer = !empty($s['container_code']);
                $orderStatus  = $s['order_status'] ?? '';
                [$statusLabel, $statusColor] = $orderStatusLabel[$orderStatus] ?? ['Unknown', '#94a3b8'];
                $colorStr = trim((string)($s['finish'] ?? ''));
                if ($colorStr === '') $colorStr = trim((string)($s['wood_color'] ?? ''));
            ?>
                <div class="stock-card">
                    <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:5px">
                        <span class="stock-code"><?= htmlspecialchars($s['stock_code']) ?></span>
                        <?php if ($hasContainer): ?>
                            <span class="stk-badge badge-blue" title="Container: <?= htmlspecialchars($s['container_code']) ?>">
                                <i class="bi bi-box-seam"></i> <?= htmlspecialchars($s['container_code']) ?>
                            </span>
                        <?php else: ?>
                            <span class="stk-badge badge-gray"><i class="bi bi-clock"></i> Awaiting</span>
                        <?php endif; ?>
                        <?php if ($hasOrder): ?>
                            <span class="stk-badge badge-purple">
                                <i class="bi bi-person-badge"></i> <?= htmlspecialchars($s['customer_name'] ?? 'Order') ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasOrder): ?>
                        <div class="stock-name name-clickable" onclick="openDetailModal(<?= (int)$s['id'] ?>)"
                            title="Klik lihat detail order">
                            <?= htmlspecialchars($s['product_name']) ?>
                            <i class="bi bi-arrow-up-right-square" style="font-size:11px;opacity:.6"></i>
                        </div>
                    <?php else: ?>
                        <div class="stock-name"><?= htmlspecialchars($s['product_name']) ?></div>
                    <?php endif; ?>

                    <div class="stock-meta">
                        <span style="font-weight:700;color:var(--gold)">
                            <?= rtrim(rtrim(number_format((float)$s['quantity'], 2), '0'), '.') ?>
                            <?= htmlspecialchars($s['unit']) ?>
                        </span>
                    </div>
                    <?php if (!empty($s['dimensions'])): ?>
                        <div class="stock-meta">📏 <?= htmlspecialchars($s['dimensions']) ?></div>
                    <?php endif; ?>
                    <?php if ($colorStr !== ''): ?>
                        <div class="stock-meta">🎨 <?= htmlspecialchars($colorStr) ?></div>
                    <?php endif; ?>

                    <?php if ($hasOrder): ?>
                        <div class="stock-meta" style="margin-top:5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <span style="padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;
                                 background:<?= htmlspecialchars($statusColor) ?>22;color:<?= htmlspecialchars($statusColor) ?>">
                                <?= htmlspecialchars($statusLabel) ?>
                            </span>
                            <?php if (!empty($s['order_code'])): ?>
                                <span style="font-size:10px;color:var(--muted);font-family:monospace">
                                    <?= htmlspecialchars($s['order_code']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasContainer): ?>
                        <div class="stock-meta" style="font-size:11px;color:#1e40af;margin-top:4px">
                            <i class="bi bi-geo-alt"></i>
                            <?= htmlspecialchars($s['destination_country'] ?? '') ?>
                            <?php if (!empty($s['destination_port'])): ?> – <?= htmlspecialchars($s['destination_port']) ?><?php endif; ?>
                                <?php if (!empty($s['shipment_date'])): ?> &bull; <?= date('d M Y', strtotime($s['shipment_date'])) ?><?php endif; ?>
                        </div>
                    <?php elseif ($hasOrder && in_array($orderStatus, ['qc', 'ready_ship', 'partial_ship', 'shipped', 'completed'])): ?>
                        <div class="stock-meta" style="font-size:11px;color:#92400e;margin-top:4px">
                            <i class="bi bi-exclamation-circle"></i> Belum dimasukkan container
                        </div>
                    <?php endif; ?>

                    <div class="stock-meta" style="color:#94a3b8;font-size:10px;margin-top:4px">
                        Added: <?= date('d M Y', strtotime($s['created_at'])) ?>
                    </div>
                    <div class="stock-footer no-print">
                        <?php if ($hasOrder): ?>
                            <button class="btn btn-sm" onclick="openDetailModal(<?= (int)$s['id'] ?>)" style="gap:4px;font-size:11px">
                                <i class="bi bi-eye"></i> Detail
                            </button>
                        <?php endif; ?>
                        <?php if ($canEditStock): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="openEditModal(<?= (int)$s['id'] ?>)" style="gap:4px">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <?php endif; ?>
                        <?php if ($canDeleteStock): ?>
                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= (int)$s['id'] ?>)" style="gap:4px">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ═══ CREATE MODAL ═════════════════════════════════════════════════════ -->
<div class="modal-overlay no-print" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5 style="margin:0"><i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>Add Stock Item</h5>
            <button class="modal-close" id="btnCloseCreate">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="createForm">
                <input type="hidden" name="_action" value="create">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Product Name *</label>
                        <input class="input" name="product_name" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Quantity *</label>
                        <input class="input" type="number" step="0.01" name="quantity" value="1" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Unit</label>
                        <input class="input" name="unit" value="pcs" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Dimensions</label>
                        <input class="input" name="dimensions" placeholder="120×60×75" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Wood Color</label>
                        <input class="input" name="wood_color" placeholder="Mahogany" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Finish</label>
                        <input class="input" name="finish" placeholder="Lacquer" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Specification</label>
                        <textarea name="specification" class="input" style="height:60px;font-size:13px"></textarea>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Notes</label>
                        <textarea name="notes" class="input" style="height:40px;font-size:13px"></textarea>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Link ke Customer Order
                            <span style="color:var(--muted);font-weight:400">(opsional)</span></label>
                        <select name="order_id" class="input" style="font-size:13px">
                            <option value="">— Tidak ada link order —</option>
                            <?php foreach ($ordersForLink as $ol): ?>
                                <option value="<?= (int)$ol['id'] ?>">
                                    <?= htmlspecialchars($ol['order_code'] . ' | ' . $ol['customer_name'] . ' — ' . mb_strimwidth($ol['product_name'], 0, 40, '…')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:14px">
                    <button class="btn" type="submit" style="font-size:13px"><i class="bi bi-check-circle"></i> Add Stock</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnCancelCreate" style="font-size:13px">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ EDIT MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay no-print" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5 style="margin:0"><i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Edit Stock Item</h5>
            <button class="modal-close" id="btnCloseEdit">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="editForm">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="stock_id" id="ef_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Product Name</label>
                        <input class="input" name="product_name" id="ef_product" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Quantity</label>
                        <input class="input" type="number" step="0.01" name="quantity" id="ef_qty" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Unit</label>
                        <input class="input" name="unit" id="ef_unit" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Dimensions</label>
                        <input class="input" name="dimensions" id="ef_dim" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Wood Color</label>
                        <input class="input" name="wood_color" id="ef_color" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group">
                        <label style="font-size:12px">Finish</label>
                        <input class="input" name="finish" id="ef_finish" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Specification</label>
                        <textarea name="specification" id="ef_spec" class="input" style="height:60px;font-size:13px"></textarea>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Notes</label>
                        <textarea name="notes" id="ef_notes" class="input" style="height:40px;font-size:13px"></textarea>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1">
                        <label style="font-size:12px">Link ke Customer Order
                            <span style="color:var(--muted);font-weight:400">(opsional)</span></label>
                        <select name="order_id" id="ef_order" class="input" style="font-size:13px">
                            <option value="">— Tidak ada link order —</option>
                            <?php foreach ($ordersForLink as $ol): ?>
                                <option value="<?= (int)$ol['id'] ?>">
                                    <?= htmlspecialchars($ol['order_code'] . ' | ' . $ol['customer_name'] . ' — ' . mb_strimwidth($ol['product_name'], 0, 40, '…')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:14px">
                    <button class="btn" type="submit" style="font-size:13px"><i class="bi bi-check-circle"></i> Save Changes</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnCancelEdit" style="font-size:13px">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ DETAIL MODAL ═════════════════════════════════════════════════════ -->
<div class="modal-overlay no-print" id="detailModal">
    <div class="modal-box" style="width:min(820px,96vw)">
        <div class="modal-header">
            <h5 style="margin:0"><i class="bi bi-clipboard2-check me-2" style="color:var(--gold)"></i>Detail Barang</h5>
            <button class="modal-close" id="btnCloseDetail">&times;</button>
        </div>
        <div class="modal-body" id="dm_body"></div>
    </div>
</div>

<!-- DELETE FORM -->
<form method="post" id="deleteForm" style="display:none">
    <input type="hidden" name="_action" value="delete">
    <input type="hidden" name="stock_id" id="df_id">
</form>

<script>
    const PWF_STOCKS = <?= json_encode($stocksForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const ORDER_STATUS_MAP = <?= json_encode($orderStatusLabel) ?>;
    const CONTAINER_STATUS_MAP = <?= json_encode($containerStatusLabel) ?>;

    function pwfOpen(id) {
        document.getElementById(id).classList.add('open');
    }

    function pwfClose(id) {
        document.getElementById(id).classList.remove('open');
    }

    ['createModal', 'editModal', 'detailModal'].forEach(id => {
        document.getElementById(id).addEventListener('click', e => {
            if (e.target.id === id) pwfClose(id);
        });
    });

    /* CREATE */
    document.getElementById('btnAddStock').addEventListener('click', () => pwfOpen('createModal'));
    document.getElementById('btnCloseCreate').addEventListener('click', () => pwfClose('createModal'));
    document.getElementById('btnCancelCreate').addEventListener('click', () => pwfClose('createModal'));

    /* EDIT */
    document.getElementById('btnCloseEdit').addEventListener('click', () => pwfClose('editModal'));
    document.getElementById('btnCancelEdit').addEventListener('click', () => pwfClose('editModal'));

    function openEditModal(stockId) {
        const d = PWF_STOCKS[stockId];
        if (!d) return;
        document.getElementById('ef_id').value = d.id;
        document.getElementById('ef_product').value = d.product_name || '';
        document.getElementById('ef_qty').value = d.quantity || 1;
        document.getElementById('ef_unit').value = d.unit || 'pcs';
        document.getElementById('ef_dim').value = d.dimensions || '';
        document.getElementById('ef_color').value = d.wood_color || '';
        document.getElementById('ef_finish').value = d.finish || '';
        document.getElementById('ef_spec').value = d.specification || '';
        document.getElementById('ef_notes').value = d.notes || '';
        const sel = document.getElementById('ef_order');
        sel.value = d.order_id ? String(d.order_id) : '';
        pwfOpen('editModal');
    }

    /* DELETE */
    function confirmDelete(stockId) {
        if (confirm('Hapus stock item ini?')) {
            document.getElementById('df_id').value = stockId;
            document.getElementById('deleteForm').submit();
        }
    }

    /* DETAIL */
    document.getElementById('btnCloseDetail').addEventListener('click', () => pwfClose('detailModal'));

    function fmt(v) {
        return (v !== null && v !== undefined && v !== '') ? v : '—';
    }

    function fmtDate(d) {
        if (!d) return '—';
        try {
            const dt = new Date(d.replace(' ', 'T'));
            return dt.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch {
            return d;
        }
    }

    function fmtMoney(v) {
        if (!v || +v === 0) return '—';
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(+v);
    }

    function statusBadge(status, map) {
        if (!status || !map[status]) return '—';
        const [label, color] = map[status];
        return `<span style="padding:3px 10px;border-radius:20px;background:${color}22;color:${color};font-weight:700;font-size:11px">${label}</span>`;
    }

    function openDetailModal(stockId) {
        const d = PWF_STOCKS[stockId];
        if (!d) return;
        const hasOrder = !!d.order_id;
        const hasContainer = !!d.container_code;
        let html = '';

        html += `<div class="det-section">
        <div class="det-section-title">📦 Info Stock</div>
        <div class="detail-grid">
            <div><div class="det-label">Stock Code</div>
                 <div class="det-value" style="font-family:monospace;color:var(--gold);font-weight:700">${fmt(d.stock_code)}</div></div>
            <div><div class="det-label">Nama Produk</div>
                 <div class="det-value" style="font-weight:800">${fmt(d.product_name)}</div></div>
            <div><div class="det-label">Qty</div>
                 <div class="det-value"><strong>${fmt(d.quantity)}</strong> ${fmt(d.unit)}</div></div>
            <div><div class="det-label">Dimensi</div><div class="det-value">${fmt(d.dimensions)}</div></div>
            <div><div class="det-label">Wood Color</div><div class="det-value">${fmt(d.wood_color)}</div></div>
            <div><div class="det-label">Finish</div><div class="det-value">${fmt(d.finish)}</div></div>
            ${d.specification ? `<div class="full"><div class="det-label">Specification</div><div class="det-value">${fmt(d.specification)}</div></div>` : ''}
            ${d.notes ? `<div class="full"><div class="det-label">Notes</div><div class="det-value">${fmt(d.notes)}</div></div>` : ''}
            <div><div class="det-label">Ditambahkan</div><div class="det-value">${fmtDate(d.created_at)}</div></div>
        </div></div>`;

        if (hasOrder) {
            html += `<div class="det-section" style="border-left:3px solid var(--gold)">
            <div class="det-section-title">🛒 Customer Order</div>
            <div class="detail-grid">
                <div><div class="det-label">Order Code</div>
                     <div class="det-value" style="font-family:monospace;color:var(--gold);font-weight:700">${fmt(d.order_code)}</div></div>
                <div><div class="det-label">Customer</div>
                     <div class="det-value" style="font-weight:700">${fmt(d.customer_name)}</div></div>
                <div><div class="det-label">Tanggal Order</div><div class="det-value">${fmtDate(d.order_date)}</div></div>
                <div><div class="det-label">Due Date</div><div class="det-value">${fmtDate(d.due_date)}</div></div>
                <div><div class="det-label">Order Qty</div><div class="det-value">${fmt(d.o_quantity)} ${fmt(d.o_unit)}</div></div>
                <div><div class="det-label">Status Order</div><div class="det-value">${statusBadge(d.order_status, ORDER_STATUS_MAP)}</div></div>
                ${+d.unit_price>0 ? `<div><div class="det-label">Unit Price</div><div class="det-value">${fmtMoney(d.unit_price)}</div></div>` : ''}
                ${+d.total_price>0 ? `<div><div class="det-label">Total Price</div><div class="det-value" style="font-weight:700;color:var(--gold)">${fmtMoney(d.total_price)}</div></div>` : ''}
                ${d.o_dimensions ? `<div><div class="det-label">Dimensi</div><div class="det-value">${fmt(d.o_dimensions)}</div></div>` : ''}
                ${d.o_wood_color ? `<div><div class="det-label">Wood Color</div><div class="det-value">${fmt(d.o_wood_color)}</div></div>` : ''}
                ${d.o_finish ? `<div><div class="det-label">Finish</div><div class="det-value">${fmt(d.o_finish)}</div></div>` : ''}
                ${d.o_specification ? `<div class="full"><div class="det-label">Spesifikasi</div><div class="det-value">${fmt(d.o_specification)}</div></div>` : ''}
                ${d.o_notes ? `<div class="full"><div class="det-label">Catatan Order</div><div class="det-value">${fmt(d.o_notes)}</div></div>` : ''}
            </div></div>`;
        }

        if (hasContainer) {
            html += `<div class="det-section" style="background:#eff6ff;border:1px solid #bfdbfe">
            <div class="det-section-title" style="color:#1d4ed8">🚢 Container Assigned</div>
            <div class="detail-grid">
                <div><div class="det-label">Container Code</div>
                     <div class="det-value" style="font-family:monospace;font-weight:700;color:#1d4ed8">${fmt(d.container_code)}</div></div>
                <div><div class="det-label">Container No</div><div class="det-value">${fmt(d.container_no)}</div></div>
                <div><div class="det-label">Type</div><div class="det-value">${fmt(d.container_type)}</div></div>
                <div><div class="det-label">Status</div><div class="det-value">${statusBadge(d.container_status, CONTAINER_STATUS_MAP)}</div></div>
                <div><div class="det-label">Tujuan</div>
                     <div class="det-value">${fmt(d.destination_country)}${d.destination_port ? ' — '+d.destination_port : ''}</div></div>
                <div><div class="det-label">Tanggal Kirim</div><div class="det-value">${fmtDate(d.shipment_date)}</div></div>
                ${+d.qty_shipped>0 ? `<div><div class="det-label">Qty Shipped</div><div class="det-value">${fmt(d.qty_shipped)} ${fmt(d.unit)}</div></div>` : ''}
            </div></div>`;
        } else if (hasOrder) {
            html += `<div style="text-align:center;padding:12px;color:#92400e;background:#fef3c7;border-radius:8px;
                             font-size:12px;font-weight:600;margin-top:12px;border:1px solid #fde68a">
            <i class="bi bi-clock" style="margin-right:6px"></i>Barang belum dimasukkan ke dalam container
        </div>`;
        }

        document.getElementById('dm_body').innerHTML = html;
        pwfOpen('detailModal');
    }
</script>
<?php pwfOfficeFooter(); ?>
</script>