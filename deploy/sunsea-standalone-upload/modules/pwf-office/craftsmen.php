<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int)($_POST['craftsman_id'] ?? 0);
        if ($id > 0) {
            $stmtOrders = $pdo->prepare('SELECT COUNT(*) FROM pwf_orders WHERE assigned_craftsman_id = ?');
            $stmtOrders->execute([$id]);
            $usedInOrders = (int)$stmtOrders->fetchColumn();

            $stmtProgress = $pdo->prepare('SELECT COUNT(*) FROM pwf_order_progress WHERE craftsman_id = ?');
            $stmtProgress->execute([$id]);
            $usedInProgress = (int)$stmtProgress->fetchColumn();

            if ($usedInOrders > 0 || $usedInProgress > 0) {
                $msgType = 'warning';
                $msg = 'Craftsman cannot be deleted because it is already used in orders/progress.';
            } else {
                $pdo->prepare('DELETE FROM pwf_craftsmen WHERE id = ?')->execute([$id]);
                $msg = 'Craftsman deleted successfully.';
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['craftsman_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE pwf_craftsmen SET craftsman_name=?, phone=?, specialty=?, notes=? WHERE id=?')
                ->execute([
                    trim($_POST['craftsman_name'] ?? ''),
                    trim($_POST['phone']          ?? ''),
                    trim($_POST['specialty']       ?? ''),
                    trim($_POST['notes']           ?? ''),
                    $id,
                ]);
            $msg = 'Craftsman updated successfully.';
        }
    } else {
        $name      = trim($_POST['craftsman_name'] ?? '');
        $phone     = trim($_POST['phone']          ?? '');
        $specialty = trim($_POST['specialty']       ?? '');
        $notes     = trim($_POST['notes']           ?? '');

        if ($name !== '') {
            $code = genPwfCode($pdo, 'TKG');
            $pdo->prepare('INSERT INTO pwf_craftsmen (craftsman_code, craftsman_name, phone, specialty, notes) VALUES (?,?,?,?,?)')
                ->execute([$code, $name, $phone, $specialty, $notes]);
            $msg = 'Craftsman added successfully.';
        }
    }
}

$rows = $pdo->query('SELECT * FROM pwf_craftsmen ORDER BY id DESC')->fetchAll();

// Build id-keyed JS data for edit modal
$rowsJs = [];
foreach ($rows as $r) {
    $rowsJs[(int)$r['id']] = $r;
}

pwfOfficeHeader('Craftsmen', 'craftsmen');
?>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="width:min(480px,96vw)">
        <div class="modal-header">
            <h5 style="margin:0"><i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Edit Craftsman</h5>
            <button class="modal-close" id="btnCloseEdit">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="editForm">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="craftsman_id" id="ef_id">
                <div class="pwf-form-group"><label>Full Name</label><input class="input" name="craftsman_name" id="ef_name" required></div>
                <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="phone" id="ef_phone"></div>
                <div class="pwf-form-group"><label>Specialty</label><input class="input" name="specialty" id="ef_specialty"></div>
                <div class="pwf-form-group"><label>Notes</label><textarea name="notes" id="ef_notes" style="height:70px"></textarea></div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn" type="submit"><i class="bi bi-check-circle"></i> Save Changes</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnCancelEdit">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">Add Craftsman</div>
        <div class="pwf-card-body">
            <?php if ($msg): ?>
                <div class="alert alert-<?= $msgType === 'warning' ? 'warning' : 'success' ?>">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="_action" value="create">
                <div class="pwf-form-group"><label>Full Name</label><input class="input" name="craftsman_name" placeholder="Craftsman name" required></div>
                <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="phone" placeholder="+62 ..."></div>
                <div class="pwf-form-group"><label>Specialty</label><input class="input" name="specialty" placeholder="e.g. Carving, Finishing, Assembly"></div>
                <div class="pwf-form-group"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
                <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Craftsman</button>
            </form>
        </div>
    </div>

    <div class="pwf-card">
        <div class="pwf-card-header">Craftsmen List</div>
        <div style="padding:0">
            <table class="pwf-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Specialty</th>
                        <th style="width:130px;text-align:center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['craftsman_code']) ?></code></td>
                            <td><?= htmlspecialchars($r['craftsman_name']) ?></td>
                            <td><?= htmlspecialchars($r['specialty']) ?></td>
                            <td style="text-align:center">
                                <div style="display:flex;gap:5px;justify-content:center">
                                    <button type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        style="padding:4px 9px;font-size:11px"
                                        onclick="openEditModal(<?= (int)$r['id'] ?>)">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <form method="post" onsubmit="return confirm('Delete this craftsman?');" style="margin:0">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="craftsman_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:4px 9px;font-size:11px">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No craftsmen yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
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
</style>

<script>
    const CRAFTSMEN_DATA = <?= json_encode($rowsJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function openEditModal(id) {
        const d = CRAFTSMEN_DATA[id];
        if (!d) return;
        document.getElementById('ef_id').value = d.id;
        document.getElementById('ef_name').value = d.craftsman_name || '';
        document.getElementById('ef_phone').value = d.phone || '';
        document.getElementById('ef_specialty').value = d.specialty || '';
        document.getElementById('ef_notes').value = d.notes || '';
        document.getElementById('editModal').classList.add('open');
    }
    document.getElementById('btnCloseEdit').addEventListener('click', () => document.getElementById('editModal').classList.remove('open'));
    document.getElementById('btnCancelEdit').addEventListener('click', () => document.getElementById('editModal').classList.remove('open'));
    document.getElementById('editModal').addEventListener('click', e => {
        if (e.target.id === 'editModal') document.getElementById('editModal').classList.remove('open');
    });
</script>

<?php pwfOfficeFooter();
