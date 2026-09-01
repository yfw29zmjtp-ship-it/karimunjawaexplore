<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();

// Ensure dropped_at column exists
try {
    $pdo->query("SELECT dropped_at FROM pwf_containers LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE pwf_containers ADD COLUMN dropped_at DATETIME NULL DEFAULT NULL");
}

function fmtQty($v)
{
    return rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
}

$msg = '';
$msgType = 'success';

// ─── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_container') {
        $shipDate  = $_POST['shipment_date'] ?? date('Y-m-d');
        $ctype     = $_POST['container_type'] ?? '40hc';
        $country   = trim($_POST['destination_country'] ?? '');
        $port      = trim($_POST['destination_port'] ?? '');
        $forwarder = trim($_POST['forwarder'] ?? '');
        $tracking  = trim($_POST['tracking_no'] ?? '');
        $blno      = trim($_POST['bl_no'] ?? '');
        $cno       = trim($_POST['container_no'] ?? '');
        $status    = $_POST['status'] ?? 'draft';
        $notes     = trim($_POST['notes'] ?? '');

        $ym  = date('Ym');
        // Wrap counter + insert in a transaction so concurrent creates don't collide on container_code.
        $pdo->beginTransaction();
        try {
            $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM pwf_containers WHERE container_code LIKE ?");
            $stmtCnt->execute(['CTN-' . $ym . '-%']);
            $cnt = (int)$stmtCnt->fetchColumn() + 1;
            $code = sprintf('CTN-%s-%03d', $ym, $cnt);
            $pdo->prepare('INSERT INTO pwf_containers (container_code,container_no,container_type,shipment_date,destination_country,destination_port,forwarder,tracking_no,bl_no,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $cno, $ctype, $shipDate, $country, $port, $forwarder, $tracking, $blno, $status, $notes, $_SESSION['user_id'] ?? null]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Failed to create container: ' . htmlspecialchars($e->getMessage());
            header("Location: db-containers.php?msg=" . urlencode(strip_tags($msg)));
            exit;
        }
        $msg = "Container <strong>$code</strong> created successfully.";
        header("Location: db-containers.php?msg=" . urlencode(strip_tags($msg)));
        exit;
    } elseif ($action === 'edit_container') {
        $cid      = (int)($_POST['container_id'] ?? 0);
        $shipDate = $_POST['shipment_date'] ?? date('Y-m-d');
        $ctype    = $_POST['container_type'] ?? '40hc';
        $country  = trim($_POST['destination_country'] ?? '');
        $port     = trim($_POST['destination_port'] ?? '');
        $forwarder = trim($_POST['forwarder'] ?? '');
        $tracking = trim($_POST['tracking_no'] ?? '');
        $blno     = trim($_POST['bl_no'] ?? '');
        $cno      = trim($_POST['container_no'] ?? '');
        $status   = $_POST['status'] ?? 'draft';
        $notes    = trim($_POST['notes'] ?? '');
        if ($cid > 0) {
            $pdo->prepare('UPDATE pwf_containers SET container_no=?,container_type=?,shipment_date=?,destination_country=?,destination_port=?,forwarder=?,tracking_no=?,bl_no=?,status=?,notes=?,updated_at=NOW() WHERE id=?')
                ->execute([$cno, $ctype, $shipDate, $country, $port, $forwarder, $tracking, $blno, $status, $notes, $cid]);
            $msg = 'Container updated.';
        }
        header("Location: db-containers.php?msg=" . urlencode($msg));
        exit;
    } elseif ($action === 'delete_container') {
        $cid = (int)($_POST['container_id'] ?? 0);
        if ($cid > 0) {
            $stmtOids = $pdo->prepare("SELECT DISTINCT order_id FROM pwf_container_items WHERE container_id=?");
            $stmtOids->execute([$cid]);
            $orderIds = $stmtOids->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('DELETE FROM pwf_container_items WHERE container_id=?')->execute([$cid]);
            $qOrderQty   = $pdo->prepare("SELECT quantity FROM pwf_orders WHERE id=?");
            $qShipped    = $pdo->prepare("SELECT COALESCE(SUM(qty_shipped),0) FROM pwf_container_items WHERE order_id=?");
            $qQtyDone    = $pdo->prepare("SELECT qty_done FROM pwf_orders WHERE id=?");
            $uStatus     = $pdo->prepare('UPDATE pwf_orders SET status=?,updated_at=NOW() WHERE id=?');
            foreach ($orderIds as $oid) {
                $oid = (int)$oid;
                $qOrderQty->execute([$oid]);
                $orderQty     = (float)$qOrderQty->fetchColumn();
                $qShipped->execute([$oid]);
                $totalShipped = (float)$qShipped->fetchColumn();
                if ($totalShipped <= 0) {
                    $qQtyDone->execute([$oid]);
                    $qtyDone = (float)$qQtyDone->fetchColumn();
                    $revert  = ($qtyDone >= $orderQty) ? 'ready_ship' : 'on_progress';
                } else {
                    $revert = ($totalShipped >= $orderQty) ? 'shipped' : 'partial_ship';
                }
                $uStatus->execute([$revert, $oid]);
            }
            $pdo->prepare('DELETE FROM pwf_containers WHERE id=?')->execute([$cid]);
            $msg = 'Container deleted. Order statuses reverted.';
            header("Location: db-containers.php?msg=" . urlencode($msg));
            exit;
        }
    }
}
if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// ─── DATA ──────────────────────────────────────────────────────────────────────
$containers = $pdo->query("
    SELECT ct.*,
           COUNT(ci.id) AS item_count,
           COALESCE(SUM(ci.qty_shipped),0) AS total_qty,
           COUNT(DISTINCT o2.customer_id) AS cust_count
    FROM pwf_containers ct
    LEFT JOIN pwf_container_items ci ON ci.container_id=ct.id
    LEFT JOIN pwf_orders o2 ON o2.id=ci.order_id
    GROUP BY ct.id
    ORDER BY ct.id DESC
")->fetchAll();

$totalContainers = count($containers);
$cntDraft   = count(array_filter($containers, fn($c) => $c['status'] === 'draft'));
$cntBooked  = count(array_filter($containers, fn($c) => $c['status'] === 'booked'));
$cntOnboard = count(array_filter($containers, fn($c) => in_array($c['status'], ['onboard', 'arrived'])));
$cntClosed  = count(array_filter($containers, fn($c) => $c['status'] === 'closed'));
$totalPcs   = (float)array_sum(array_column($containers, 'total_qty'));

pwfOfficeHeader('Database – Containers', 'containers');
?>

<?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom:16px"><?= $msg ?></div>
<?php endif; ?>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div>
        <div style="font-size:18px;font-weight:800;color:var(--text)"><i class="bi bi-box-seam me-2" style="color:var(--gold)"></i>Containers</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px">Manage all shipping containers · <?= $totalContainers ?> total</div>
    </div>
    <button type="button" data-bs-toggle="modal" data-bs-target="#createModal"
        style="display:inline-flex;align-items:center;gap:8px;padding:10px 22px;background:var(--gold);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer">
        <i class="bi bi-plus-lg"></i> New Container
    </button>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px">
    <div class="pwf-card" style="padding:14px 16px;text-align:center">
        <div style="font-size:22px;font-weight:800;color:var(--text)"><?= $totalContainers ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px">Total</div>
    </div>
    <div class="pwf-card" style="padding:14px 16px;text-align:center;border-top:3px solid #6b7280">
        <div style="font-size:22px;font-weight:800;color:#6b7280"><?= $cntDraft ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px">Draft</div>
    </div>
    <div class="pwf-card" style="padding:14px 16px;text-align:center;border-top:3px solid var(--gold)">
        <div style="font-size:22px;font-weight:800;color:var(--gold)"><?= $cntBooked ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px">Booked</div>
    </div>
    <div class="pwf-card" style="padding:14px 16px;text-align:center;border-top:3px solid #8b5cf6">
        <div style="font-size:22px;font-weight:800;color:#8b5cf6"><?= $cntOnboard ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px">Onboard</div>
    </div>
    <div class="pwf-card" style="padding:14px 16px;text-align:center;border-top:3px solid #22c55e">
        <div style="font-size:22px;font-weight:800;color:#22c55e"><?= $cntClosed ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px">Closed</div>
    </div>
</div>

<!-- Table -->
<div class="pwf-card" style="overflow:hidden">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12.5px;font-weight:700;color:var(--text)">All Containers</span>
        <span style="font-size:11px;color:var(--muted)"><?= fmtQty($totalPcs) ?> pcs total shipped</span>
    </div>
    <?php if (empty($containers)): ?>
        <div style="padding:48px;text-align:center;color:var(--muted)">
            <i class="bi bi-inbox" style="font-size:36px;display:block;margin-bottom:10px"></i>
            <div style="font-size:14px;font-weight:600">No containers yet</div>
            <div style="font-size:12px;margin-top:4px">Click "New Container" to create the first one</div>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:var(--nav-hover);border-bottom:2px solid var(--border)">
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Code</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Container No</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Type</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Shipment Date</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Destination</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Forwarder</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Items</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Pcs Shipped</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Status</th>
                        <th style="padding:10px 14px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($containers as $ct):
                        $sc = match ($ct['status']) {
                            'draft'   => ['Draft',    '#6b7280', '#fff'],
                            'booked'  => ['Booked',   '#3b82f6', '#fff'],
                            'onboard' => ['On Board', '#8b5cf6', '#fff'],
                            'arrived' => ['Arrived',  '#22c55e', '#fff'],
                            'closed'  => ['Closed',   '#9ca3af', '#fff'],
                            default   => [ucfirst($ct['status']), '#6b7280', '#fff']
                        };
                        $ctJson = htmlspecialchars(json_encode([
                            'id' => $ct['id'],
                            'container_no' => $ct['container_no'],
                            'container_type' => $ct['container_type'],
                            'shipment_date' => $ct['shipment_date'],
                            'destination_country' => $ct['destination_country'],
                            'destination_port' => $ct['destination_port'],
                            'forwarder' => $ct['forwarder'],
                            'tracking_no' => $ct['tracking_no'],
                            'bl_no' => $ct['bl_no'],
                            'status' => $ct['status'],
                            'notes' => $ct['notes']
                        ]));
                    ?>
                        <tr style="border-bottom:1px solid var(--border);transition:background .15s"
                            onmouseenter="this.style.background='var(--nav-hover)'" onmouseleave="this.style.background=''">
                            <td style="padding:10px 14px"><code style="font-size:12px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($ct['container_code']) ?></code></td>
                            <td style="padding:10px 14px;font-size:12px;color:var(--muted)"><?= htmlspecialchars($ct['container_no'] ?: '—') ?></td>
                            <td style="padding:10px 14px;font-size:12px;font-weight:600;color:var(--text)"><?= strtoupper(htmlspecialchars($ct['container_type'])) ?></td>
                            <td style="padding:10px 14px;font-size:12px;color:var(--text)"><?= date('d M Y', strtotime($ct['shipment_date'])) ?></td>
                            <td style="padding:10px 14px">
                                <div style="font-size:12px;font-weight:600;color:var(--text)"><?= htmlspecialchars($ct['destination_country'] ?: '—') ?></div>
                                <?php if ($ct['destination_port']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars($ct['destination_port']) ?></div><?php endif; ?>
                            </td>
                            <td style="padding:10px 14px;font-size:12px;color:var(--text)"><?= htmlspecialchars($ct['forwarder'] ?: '—') ?></td>
                            <td style="padding:10px 14px;text-align:center;font-size:13px;font-weight:700;color:var(--text)"><?= (int)$ct['item_count'] ?><div style="font-size:9.5px;color:var(--muted)"><?= (int)$ct['cust_count'] ?> cust</div>
                            </td>
                            <td style="padding:10px 14px;text-align:center;font-size:13px;font-weight:800;color:var(--gold)"><?= fmtQty($ct['total_qty']) ?></td>
                            <td style="padding:10px 14px;text-align:center">
                                <span style="font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:<?= $sc[1] ?>;color:<?= $sc[2] ?>"><?= $sc[0] ?></span>
                            </td>
                            <td style="padding:10px 14px;text-align:center">
                                <div style="display:flex;gap:5px;justify-content:center">
                                    <button type="button" onclick="openEdit(<?= $ctJson ?>)"
                                        style="padding:4px 10px;border-radius:6px;background:#EFF6FF;border:1px solid #BFDBFE;color:#1D4ED8;font-size:11px;font-weight:600;cursor:pointer">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <a href="shipping.php?open_ctn=<?= (int)$ct['id'] ?>" target="_blank"
                                        style="display:inline-flex;align-items:center;gap:3px;padding:4px 10px;border-radius:6px;background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;font-size:11px;font-weight:600;text-decoration:none">
                                        <i class="bi bi-box-arrow-up-right"></i> View
                                    </a>
                                    <button type="button" onclick="openDelete(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars(addslashes($ct['container_code'])) ?>', <?= (int)$ct['item_count'] ?>)"
                                        style="padding:4px 10px;border-radius:6px;background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;font-size:11px;font-weight:600;cursor:pointer">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ══ MODAL: Create Container ══════════════════════════════════════════════ -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:14px 20px;border-bottom:1px solid var(--border)">
                <div style="font-size:14px;font-weight:800;color:var(--text)"><i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>New Container</div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="_action" value="create_container">
                <div class="modal-body" style="padding:20px">
                    <?php include __DIR__ . '/db-container-form.php'; ?>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" style="padding:9px 24px;background:var(--gold);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer"><i class="bi bi-plus-lg me-1"></i>Create Container</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Edit Container ════════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:14px 20px;border-bottom:1px solid var(--border)">
                <div style="font-size:14px;font-weight:800;color:var(--text)"><i class="bi bi-pencil me-2" style="color:var(--gold)"></i>Edit Container: <span id="editCode" style="color:var(--gold)"></span></div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editForm">
                <input type="hidden" name="_action" value="edit_container">
                <input type="hidden" name="container_id" id="editId">
                <div class="modal-body" style="padding:20px" id="editFormBody">
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" style="padding:9px 24px;background:var(--gold);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Delete ════════════════════════════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:13px 18px;border-bottom:1px solid var(--border);background:#FEF2F2">
                <div style="font-weight:700;font-size:14px;color:#DC2626"><i class="bi bi-trash3 me-2"></i>Delete Container</div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:18px">
                <div style="font-size:15px;font-weight:800;color:var(--gold);text-align:center;margin-bottom:8px" id="delCode"></div>
                <div style="font-size:12px;color:var(--muted);text-align:center;margin-bottom:14px" id="delInfo"></div>
                <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:11px 13px;font-size:12px;color:#DC2626">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This will <strong>permanently delete</strong> the container and all its items. Order statuses will be automatically reverted.
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="_action" value="delete_container">
                <input type="hidden" name="container_id" id="delId">
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:11px 18px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" style="padding:8px 20px;background:#DC2626;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer"><i class="bi bi-trash3 me-1"></i>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- inline styles -->
<style>
    .form-lbl {
        display: block;
        font-size: 10.5px;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 5px
    }
</style>

<script>
    // Edit modal
    function openEdit(ct) {
        document.getElementById('editId').value = ct.id;
        document.getElementById('editCode').textContent = '';
        // Build form fields
        const statusOptions = ['draft', 'booked', 'onboard', 'arrived', 'closed'];
        const statusLabels = {
            draft: 'Draft',
            booked: 'Booked',
            onboard: 'On Board',
            arrived: 'Arrived',
            closed: 'Closed'
        };

        function fld(label, inputHtml) {
            return `<div><label class="form-lbl">${label}</label>${inputHtml}</div>`;
        }

        function sel(name, val, opts) {
            const o = opts.map(v => `<option value="${v}" ${val===v?'selected':''}>${statusLabels[v]||v}</option>`).join('');
            return `<select name="${name}" class="select" style="width:100%">${o}</select>`;
        }

        function inp(name, val, ph) {
            return `<input name="${name}" class="input" value="${(val||'').replace(/"/g,'&quot;')}" placeholder="${ph||''}" style="width:100%">`;
        }
        const typeOpts = ['20ft', '40ft', '40hc', 'lcl', 'fcl'];
        document.getElementById('editFormBody').innerHTML = `
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px">
            ${fld('Container No (Physical)',inp('container_no',ct.container_no,'e.g. TEMU2134567'))}
            ${fld('Shipment Date',`<input name="shipment_date" type="date" class="input" value="${ct.shipment_date||''}" style="width:100%">`)}
            ${fld('Type',`<select name="container_type" class="select" style="width:100%">${typeOpts.map(t=>`<option value="${t}" ${ct.container_type===t?'selected':''}>${t.toUpperCase()}</option>`).join('')}</select>`)}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
            ${fld('Destination Country',inp('destination_country',ct.destination_country,'e.g. Japan'))}
            ${fld('Destination Port',inp('destination_port',ct.destination_port,'e.g. Osaka'))}
            ${fld('Forwarder',inp('forwarder',ct.forwarder,'Forwarding company'))}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
            ${fld('Bill of Lading No',inp('bl_no',ct.bl_no,'BL Number'))}
            ${fld('Tracking No',inp('tracking_no',ct.tracking_no,'Tracking / booking no'))}
            ${fld('Status',sel('status',ct.status,statusOptions))}
        </div>
        <div>${fld('Notes',`<textarea name="notes" class="input" style="width:100%;min-height:56px;resize:vertical">${(ct.notes||'').replace(/</g,'&lt;')}</textarea>`)}</div>
    `;
        // update title
        document.getElementById('editCode').textContent = ct.container_code || '';
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    // Delete modal
    function openDelete(cid, code, items) {
        document.getElementById('delId').value = cid;
        document.getElementById('delCode').textContent = code;
        document.getElementById('delInfo').textContent = items + ' items will be removed from this container';
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>
<?php pwfOfficeFooter(); ?>