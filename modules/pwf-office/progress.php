<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'update_achievement') {
        $id       = (int)($_POST['order_id'] ?? 0);
        $qtyDone  = max(0, (float)($_POST['qty_done'] ?? 0));
        $note     = trim($_POST['note'] ?? '');
        $craftsmanId = (int)($_POST['craftsman_id'] ?? 0) ?: null;

        if ($id > 0) {
            $row = $pdo->prepare('SELECT quantity FROM pwf_orders WHERE id=?');
            $row->execute([$id]);
            $qty = max(0.0001, (float)($row->fetchColumn() ?: 1));
            $qtyDone = min($qtyDone, $qty);
            $pct = min(100, (int)round($qtyDone / $qty * 100));
            $newStatus = ($qtyDone >= $qty) ? 'completed' : 'on_progress';

            $pdo->prepare('UPDATE pwf_orders SET qty_done=?, progress_percent=?, status=?,
                assigned_craftsman_id=COALESCE(?,assigned_craftsman_id), updated_at=NOW() WHERE id=?')
                ->execute([$qtyDone, $pct, $newStatus, $craftsmanId, $id]);

            // Log to progress history
            $pdo->prepare('INSERT INTO pwf_order_progress (order_id,craftsman_id,progress_date,achievement_percent,work_note) VALUES (?,?,NOW(),?,?)')
                ->execute([$id, $craftsmanId, $pct, $note ?: "Qty done updated to $qtyDone"]);

            $msg = 'Achievement updated. Status: ' . strtoupper(str_replace('_', ' ', $newStatus)) . '.';
            if ($newStatus === 'completed') $msg .= ' Order selesai dan otomatis Completed!';
        }
    }
}

// Data
$activeOrders = $pdo->query("
    SELECT o.id, o.order_code, o.product_name, o.quantity, o.qty_done,
           o.progress_percent, o.status, o.due_date,
           c.customer_name, t.craftsman_name, t.id AS craftsman_id,
           o.assigned_craftsman_id
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    WHERE o.status NOT IN ('completed','cancelled')
    ORDER BY FIELD(o.status,'on_progress','ready_ship','partial_ship','qc','draft','shipped'), o.id DESC
")->fetchAll();

$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();

$logs = $pdo->query("
    SELECT p.id, p.progress_date, p.achievement_percent, p.work_note,
           o.order_code, o.product_name, t.craftsman_name
    FROM pwf_order_progress p
    JOIN pwf_orders o ON o.id=p.order_id
    LEFT JOIN pwf_craftsmen t ON t.id=p.craftsman_id
    ORDER BY p.id DESC LIMIT 20
")->fetchAll();

// Summary stats
$totalActive   = count($activeOrders);
$inProgress    = count(array_filter(
    $activeOrders,
    fn($r) =>
    (float)($r['qty_done'] ?? 0) < (float)($r['quantity'] ?? 0)
        && !in_array($r['status'], ['completed', 'cancelled', 'shipped'], true)
));
$readyToShip   = count(array_filter($activeOrders, fn($r) => in_array($r['status'], ['ready_ship', 'partial_ship'])));
$totalQty      = array_sum(array_column($activeOrders, 'quantity'));
$totalDone     = array_sum(array_column($activeOrders, 'qty_done'));
$overallPct    = $totalQty > 0 ? min(100, round($totalDone / $totalQty * 100)) : 0;

pwfOfficeHeader('Achievement Tracking', 'progress');
?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'danger' ?>" style="margin-bottom:16px">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- STAT CARDS -->
<div class="stat-cards" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--status-orange-bg)"><i class="bi bi-clipboard2-check" style="color:#FB923C"></i></div>
        <div>
            <div class="stat-val"><?= $totalActive ?></div>
            <div class="stat-lbl">Active Orders</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--status-purple-bg)"><i class="bi bi-hammer" style="color:#D8B4FE"></i></div>
        <div>
            <div class="stat-val"><?= $inProgress ?></div>
            <div class="stat-lbl">In Production</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--status-green-bg)"><i class="bi bi-box-seam" style="color:#4EEE90"></i></div>
        <div>
            <div class="stat-val"><?= $readyToShip ?></div>
            <div class="stat-lbl">Ready to Ship</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(184,134,11,.18)"><i class="bi bi-bar-chart-line" style="color:var(--gold)"></i></div>
        <div>
            <div class="stat-val"><?= $overallPct ?>%</div>
            <div class="stat-lbl">Overall Progress</div>
            <div style="margin-top:4px;height:4px;background:var(--border);border-radius:10px;width:100px">
                <div style="width:<?= $overallPct ?>%;height:100%;background:var(--gold);border-radius:10px"></div>
            </div>
        </div>
    </div>
</div>

<!-- ORDER ACHIEVEMENT TABLE -->
<div class="pwf-card" style="margin-bottom:20px">
    <div class="pwf-card-header">
        <i class="bi bi-trophy me-2" style="color:var(--gold)"></i>Order Achievement
        <span style="margin-left:auto;font-size:11px;color:var(--muted)"><?= $totalDone ?> / <?= $totalQty ?> pcs total done</span>
    </div>
    <div style="overflow-x:auto">
        <table class="pwf-table">
            <thead>
                <tr>
                    <th style="width:130px">Order</th>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Craftsman</th>
                    <th style="width:80px">Total Qty</th>
                    <th style="min-width:200px">Achievement</th>
                    <th style="width:90px">Status</th>
                    <th style="width:120px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activeOrders as $o):
                    $pct      = (int)$o['progress_percent'];
                    $qtyDone  = (float)$o['qty_done'];
                    $qty      = (float)$o['quantity'];
                    $remaining = max(0, $qty - $qtyDone);
                    $barColor = $pct >= 100 ? '#15803D' : ($pct >= 60 ? '#D97706' : 'var(--gold)');
                    $isOverdue = $o['due_date'] && strtotime($o['due_date']) < time() && $o['status'] !== 'ready_ship';
                ?>
                    <tr>
                        <td>
                            <code style="font-size:11.5px;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code>
                            <?php if ($isOverdue): ?>
                                <div style="font-size:10px;color:#DC2626;font-weight:600;margin-top:2px">⚠ Overdue</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="font-size:12.5px"><?= htmlspecialchars($o['product_name']) ?></strong>
                            <?php if ($o['due_date']): ?>
                                <div style="font-size:11px;color:var(--muted)">Due: <?= date('d M Y', strtotime($o['due_date'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                        <td style="font-size:12px"><?= htmlspecialchars($o['craftsman_name'] ?? '—') ?></td>
                        <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format($qty, 2), '0'), '.') ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;min-width:80px">
                                    <div style="height:6px;background:var(--border);border-radius:20px;overflow:hidden">
                                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:20px;transition:width .4s"></div>
                                    </div>
                                    <div style="font-size:10.5px;color:var(--muted);margin-top:3px">
                                        <?= rtrim(rtrim(number_format($qtyDone, 2), '0'), '.') ?> done &middot; <?= rtrim(rtrim(number_format($remaining, 2), '0'), '.') ?> remaining
                                    </div>
                                </div>
                                <span style="font-size:12px;font-weight:700;color:<?= $barColor ?>;min-width:34px"><?= $pct ?>%</span>
                            </div>
                        </td>
                        <td><span class="status-badge status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $o['status'])) ?></span></td>
                        <td>
                            <button class="btn btn-sm" onclick="openUpdate(<?= htmlspecialchars(json_encode([
                                                                                'id'           => (int)$o['id'],
                                                                                'code'         => $o['order_code'],
                                                                                'name'         => $o['product_name'],
                                                                                'qty'          => $qty,
                                                                                'qty_done'     => $qtyDone,
                                                                                'craftsman_id' => (int)($o['assigned_craftsman_id'] ?? 0),
                                                                            ]), ENT_QUOTES) ?>)"
                                style="font-size:11px;padding:4px 10px;background:#F5F3FF;border:1px solid #DDD6FE;color:#6D28D9">
                                <i class="bi bi-pencil-square"></i> Update
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($activeOrders)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--muted);padding:32px">No active orders.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- UPDATE MODAL -->
<div class="modal fade" id="updateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden">
            <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);background:var(--card)">
                <div>
                    <div id="umTitle" style="font-weight:700;font-size:14px;color:var(--text)"></div>
                    <div id="umCode" style="font-size:11px;color:var(--muted)"></div>
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="_action" value="update_achievement">
                <input type="hidden" name="order_id" id="umId">
                <div class="modal-body" style="padding:20px;background:var(--card);display:flex;flex-direction:column;gap:14px">

                    <div>
                        <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:8px">
                            Qty Done (sudah selesai dibuat tukang)
                        </label>
                        <div style="display:flex;align-items:center;gap:12px">
                            <input type="number" name="qty_done" id="umQtyDone" min="0" step="0.5"
                                style="width:100px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:18px;font-weight:700;font-family:inherit;outline:none;text-align:center"
                                oninput="umCalc()">
                            <span style="font-size:13px;color:var(--muted)">/ <strong id="umQtyTotal" style="color:var(--text)"></strong> pcs total</span>
                        </div>
                        <div style="margin-top:10px">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span style="font-size:11px;color:var(--muted)">Progress</span>
                                <span id="umPct" style="font-size:13px;font-weight:700;color:var(--gold)">0%</span>
                            </div>
                            <div style="height:8px;background:var(--border);border-radius:20px;overflow:hidden">
                                <div id="umBar" style="width:0%;height:100%;background:var(--gold);border-radius:20px;transition:width .3s"></div>
                            </div>
                            <div id="umStatusHint" style="font-size:11px;margin-top:5px;color:var(--muted)"></div>
                        </div>
                    </div>

                    <div>
                        <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Craftsman</label>
                        <select name="craftsman_id" id="umCraftsman" class="select" style="width:100%">
                            <option value="">— Keep existing —</option>
                            <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Notes</label>
                        <textarea name="note" id="umNote" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg);color:var(--text);font-family:inherit;font-size:13px;min-height:60px;outline:none;resize:none" placeholder="Describe achievement today..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;background:var(--card);gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background:var(--gold);color:#fff;font-weight:600;border:none">
                        <i class="bi bi-check-circle"></i> Save Achievement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let umMaxQty = 1;

    function openUpdate(o) {
        umMaxQty = o.qty;
        document.getElementById('umTitle').textContent = o.name;
        document.getElementById('umCode').textContent = o.code;
        document.getElementById('umId').value = o.id;
        document.getElementById('umQtyTotal').textContent = o.qty;
        document.getElementById('umQtyDone').value = o.qty_done;
        document.getElementById('umQtyDone').max = o.qty;
        document.getElementById('umCraftsman').value = o.craftsman_id || '';
        document.getElementById('umNote').value = '';
        umCalc();
        new bootstrap.Modal(document.getElementById('updateModal')).show();
    }

    function umCalc() {
        const done = parseFloat(document.getElementById('umQtyDone').value) || 0;
        const pct = Math.min(100, Math.round(done / umMaxQty * 100));
        document.getElementById('umPct').textContent = pct + '%';
        document.getElementById('umBar').style.width = pct + '%';
        document.getElementById('umBar').style.background = pct >= 100 ? '#15803D' : pct >= 60 ? '#D97706' : 'var(--gold)';
        const hint = document.getElementById('umStatusHint');
        if (pct >= 100) {
            hint.textContent = '✓ Semua selesai → status akan berubah ke Ready to Ship';
            hint.style.color = '#15803D';
        } else if (pct > 0) {
            hint.textContent = Math.round(umMaxQty - done * 100) / 100 + ' pcs masih di tukang';
            hint.style.color = 'var(--muted)';
        } else {
            hint.textContent = '';
        }
    }
</script>
<?php pwfOfficeFooter();
