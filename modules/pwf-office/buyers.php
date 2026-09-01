<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = null;
$pageError = '';
try {
    $pdo = getPwfOfficePdo();
} catch (Throwable $e) {
    try {
        $dbName = 'adf_pwf';
        if (function_exists('getActiveBusinessConfig')) {
            $cfg = getActiveBusinessConfig();
            if (!empty($cfg['database'])) {
                $dbName = $cfg['database'];
            }
        }
        if (function_exists('getDbName')) {
            $dbName = getDbName($dbName);
        }

        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (Throwable $ex) {
        $pageError = 'Database connection failed for Buyers module.';
    }
}

if ($pdo instanceof PDO) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_buyers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            buyer_code VARCHAR(30) UNIQUE,
            buyer_name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(150) NULL,
            email VARCHAR(150) NULL,
            phone VARCHAR(50) NULL,
            notes TEXT NULL,
            access_key VARCHAR(80) UNIQUE,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_buyer_name (buyer_name),
            INDEX idx_access_key (access_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_buyer_customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            buyer_id INT NOT NULL,
            customer_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_buyer_customer (buyer_id, customer_id),
            INDEX idx_buyer (buyer_id),
            INDEX idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        $pageError = 'Buyer tables are not ready. Please check DB permissions.';
    }
}

function genBuyerAccessKey(PDO $pdo): string
{
    do {
        try {
            $key = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $key = sha1(uniqid('buyer', true) . mt_rand());
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pwf_buyers WHERE access_key = ?');
        $stmt->execute([$key]);
        $exists = (int)$stmt->fetchColumn() > 0;
    } while ($exists);

    return $key;
}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && $pageError === '') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $buyerName = trim((string)($_POST['buyer_name'] ?? ''));
        $contact = trim((string)($_POST['contact_person'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $customerIds = array_map('intval', $_POST['customer_ids'] ?? []);

        if ($buyerName !== '') {
            $buyerCode = genPwfCode($pdo, 'BUY');
            $accessKey = genBuyerAccessKey($pdo);

            $stmt = $pdo->prepare('INSERT INTO pwf_buyers (buyer_code, buyer_name, contact_person, email, phone, notes, access_key, is_active) VALUES (?,?,?,?,?,?,?,1)');
            $stmt->execute([$buyerCode, $buyerName, $contact, $email, $phone, $notes, $accessKey]);
            $buyerId = (int)$pdo->lastInsertId();

            if (!empty($customerIds)) {
                $ins = $pdo->prepare('INSERT IGNORE INTO pwf_buyer_customers (buyer_id, customer_id) VALUES (?,?)');
                foreach ($customerIds as $cid) {
                    if ($cid > 0) {
                        $ins->execute([$buyerId, $cid]);
                    }
                }
            }

            $msg = 'Buyer created successfully.';
        } else {
            $msg = 'Buyer name is required.';
            $msgType = 'danger';
        }
    }

    if ($action === 'update') {
        $buyerId = (int)($_POST['buyer_id'] ?? 0);
        $buyerName = trim((string)($_POST['buyer_name'] ?? ''));
        $contact = trim((string)($_POST['contact_person'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($buyerId > 0 && $buyerName !== '') {
            $stmt = $pdo->prepare('UPDATE pwf_buyers SET buyer_name=?, contact_person=?, email=?, phone=?, notes=?, is_active=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$buyerName, $contact, $email, $phone, $notes, $isActive, $buyerId]);
            $msg = 'Buyer updated successfully.';
        } else {
            $msg = 'Buyer update failed. Check buyer name.';
            $msgType = 'danger';
        }
    }

    if ($action === 'save_customers') {
        $buyerId = (int)($_POST['buyer_id'] ?? 0);
        $customerIds = array_map('intval', $_POST['customer_ids'] ?? []);

        if ($buyerId > 0) {
            $pdo->prepare('DELETE FROM pwf_buyer_customers WHERE buyer_id = ?')->execute([$buyerId]);
            if (!empty($customerIds)) {
                $ins = $pdo->prepare('INSERT IGNORE INTO pwf_buyer_customers (buyer_id, customer_id) VALUES (?,?)');
                foreach ($customerIds as $cid) {
                    if ($cid > 0) {
                        $ins->execute([$buyerId, $cid]);
                    }
                }
            }
            $msg = 'Assigned customers updated.';
        }
    }

    if ($action === 'regenerate_key') {
        $buyerId = (int)($_POST['buyer_id'] ?? 0);
        if ($buyerId > 0) {
            $newKey = genBuyerAccessKey($pdo);
            $pdo->prepare('UPDATE pwf_buyers SET access_key=?, updated_at=NOW() WHERE id=?')->execute([$newKey, $buyerId]);
            $msg = 'Buyer link regenerated successfully.';
            $msgType = 'warning';
        }
    }

    if ($action === 'delete') {
        $buyerId = (int)($_POST['buyer_id'] ?? 0);
        if ($buyerId > 0) {
            $pdo->prepare('DELETE FROM pwf_buyer_customers WHERE buyer_id=?')->execute([$buyerId]);
            $pdo->prepare('DELETE FROM pwf_buyers WHERE id=?')->execute([$buyerId]);
            $msg = 'Buyer deleted.';
            $msgType = 'warning';
        }
    }
}

$buyers = [];
$customers = [];
$assignedRows = [];
if ($pdo instanceof PDO && $pageError === '') {
    try {
        $buyers = $pdo->query("SELECT b.*, COUNT(bc.customer_id) AS customer_count
            FROM pwf_buyers b
            LEFT JOIN pwf_buyer_customers bc ON bc.buyer_id = b.id
            GROUP BY b.id
            ORDER BY b.id DESC")->fetchAll();

        $customers = $pdo->query('SELECT id, customer_code, customer_name FROM pwf_customers ORDER BY customer_name ASC')->fetchAll();
        $assignedRows = $pdo->query('SELECT buyer_id, customer_id FROM pwf_buyer_customers')->fetchAll();
    } catch (Throwable $e) {
        $pageError = 'Failed to load buyer data. Please check table structure and permissions.';
    }
}
$assignedMap = [];
foreach ($assignedRows as $row) {
    $bid = (int)$row['buyer_id'];
    $cid = (int)$row['customer_id'];
    if (!isset($assignedMap[$bid])) {
        $assignedMap[$bid] = [];
    }
    $assignedMap[$bid][] = $cid;
}

pwfOfficeHeader('Buyers', 'buyers');
?>
<style>
    .buyers-grid {
        display: grid;
        grid-template-columns: 1fr 1.6fr;
        gap: 16px;
    }

    .buyers-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .portal-link {
        font-size: 11px;
        color: var(--status-blue-text);
        word-break: break-all;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .5);
        z-index: 9000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 14px;
    }

    .modal-overlay.open {
        display: flex;
    }

    .modal-box {
        background: var(--card);
        border-radius: 14px;
        width: min(780px, 96vw);
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
    }

    .modal-head {
        padding: 14px 16px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .modal-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--text);
    }

    .modal-body {
        padding: 16px;
    }

    .customer-pick-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
        max-height: 320px;
        overflow-y: auto;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px;
        background: var(--nav-hover);
    }

    .customer-pick-item {
        display: flex;
        gap: 8px;
        align-items: flex-start;
        padding: 6px 8px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--card);
    }

    @media (max-width: 980px) {
        .buyers-grid {
            grid-template-columns: 1fr;
        }

        .customer-pick-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="buyers-grid">
    <div class="pwf-card">
        <div class="pwf-card-header">Add Buyer</div>
        <div class="pwf-card-body">
            <?php if ($pageError !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($pageError) ?></div>
            <?php endif; ?>
            <?php if ($msg): ?>
                <div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="_action" value="create">
                <div class="pwf-form-group">
                    <label>Buyer Name</label>
                    <input class="input" name="buyer_name" required placeholder="Buyer company or owner name">
                </div>
                <div class="pwf-form-group">
                    <label>Contact Person</label>
                    <input class="input" name="contact_person" placeholder="PIC name">
                </div>
                <div class="pwf-form-group">
                    <label>Email</label>
                    <input class="input" type="email" name="email" placeholder="buyer@email.com">
                </div>
                <div class="pwf-form-group">
                    <label>Phone</label>
                    <input class="input" name="phone" placeholder="+62...">
                </div>
                <div class="pwf-form-group">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Optional notes"></textarea>
                </div>
                <div class="pwf-form-group">
                    <label>Assign Customers</label>
                    <div class="customer-pick-grid" style="max-height:220px;">
                        <?php foreach ($customers as $c): ?>
                            <label class="customer-pick-item">
                                <input type="checkbox" name="customer_ids[]" value="<?= (int)$c['id'] ?>">
                                <span style="font-size:12px;line-height:1.4">
                                    <strong><?= htmlspecialchars((string)$c['customer_code']) ?></strong><br>
                                    <?= htmlspecialchars((string)$c['customer_name']) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($customers)): ?>
                            <div style="color:var(--muted);font-size:12px">No customers available.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Buyer</button>
            </form>
        </div>
    </div>

    <div class="pwf-card">
        <div class="pwf-card-header">Buyer List <span class="badge bg-secondary"><?= count($buyers) ?></span></div>
        <div style="padding:0;overflow:auto">
            <table class="pwf-table">
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th>Assigned</th>
                        <th>Portal Link</th>
                        <th style="width:260px">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($buyers as $b):
                        $portalUrl = rtrim(BASE_URL, '/') . '/modules/pwf-office/customer-portal.php?buyer_key=' . urlencode((string)$b['access_key']);
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:700"><?= htmlspecialchars((string)$b['buyer_name']) ?></div>
                                <div style="font-size:11px;color:var(--muted)">
                                    <?= htmlspecialchars((string)$b['buyer_code']) ?> · <?= !empty($b['contact_person']) ? htmlspecialchars((string)$b['contact_person']) : 'No PIC' ?>
                                </div>
                                <div style="margin-top:4px">
                                    <span class="status-badge <?= (int)$b['is_active'] === 1 ? 'status-ready_ship' : 'status-cancelled' ?>"><?= (int)$b['is_active'] === 1 ? 'active' : 'inactive' ?></span>
                                </div>
                            </td>
                            <td><strong><?= (int)$b['customer_count'] ?></strong> customer(s)</td>
                            <td>
                                <div class="portal-link"><?= htmlspecialchars($portalUrl) ?></div>
                            </td>
                            <td>
                                <div class="buyers-actions">
                                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= htmlspecialchars($portalUrl) ?>" title="Open Buyer Portal">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="copyLink('<?= htmlspecialchars($portalUrl, ENT_QUOTES) ?>')" title="Copy link">
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openEditBuyer(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openAssignCustomers(<?= (int)$b['id'] ?>, '<?= htmlspecialchars((string)$b['buyer_name'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-people"></i>
                                    </button>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Regenerate buyer link? Old link will no longer work.');">
                                        <input type="hidden" name="_action" value="regenerate_key">
                                        <input type="hidden" name="buyer_id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($buyers)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No buyers yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editBuyerModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">Edit Buyer</div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeEditBuyer()">Close</button>
        </div>
        <div class="modal-body">
            <form method="post" id="editBuyerForm">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="buyer_id" id="eb_id">
                <div class="pwf-form-group"><label>Buyer Name</label><input class="input" name="buyer_name" id="eb_name" required></div>
                <div class="pwf-form-group"><label>Contact Person</label><input class="input" name="contact_person" id="eb_contact"></div>
                <div class="pwf-form-group"><label>Email</label><input class="input" type="email" name="email" id="eb_email"></div>
                <div class="pwf-form-group"><label>Phone</label><input class="input" name="phone" id="eb_phone"></div>
                <div class="pwf-form-group"><label>Notes</label><textarea name="notes" id="eb_notes"></textarea></div>
                <div class="pwf-form-group">
                    <label>Status</label>
                    <select class="select" name="is_active" id="eb_active">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn" type="submit"><i class="bi bi-check2-circle"></i> Save</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeEditBuyer()">Cancel</button>
                </div>
            </form>
            <form method="post" id="deleteBuyerForm" style="margin-top:10px" onsubmit="return confirm('Delete this buyer and all customer assignments?');">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="buyer_id" id="db_id">
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete Buyer</button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="assignModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title" id="assignTitle">Assign Customers</div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeAssignCustomers()">Close</button>
        </div>
        <div class="modal-body">
            <form method="post" id="assignForm">
                <input type="hidden" name="_action" value="save_customers">
                <input type="hidden" name="buyer_id" id="assignBuyerId">
                <div class="customer-pick-grid" id="assignCustomerGrid">
                    <?php foreach ($customers as $c): ?>
                        <label class="customer-pick-item">
                            <input class="assign-customer-checkbox" type="checkbox" name="customer_ids[]" value="<?= (int)$c['id'] ?>">
                            <span style="font-size:12px;line-height:1.4">
                                <strong><?= htmlspecialchars((string)$c['customer_code']) ?></strong><br>
                                <?= htmlspecialchars((string)$c['customer_name']) ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn" type="submit"><i class="bi bi-check2-circle"></i> Save Assignments</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeAssignCustomers()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const assignedMap = <?= json_encode($assignedMap, JSON_UNESCAPED_UNICODE) ?>;

    function copyLink(url) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                alert('Buyer portal link copied.');
            }).catch(function() {
                prompt('Copy buyer portal link:', url);
            });
            return;
        }
        prompt('Copy buyer portal link:', url);
    }

    function openEditBuyer(b) {
        document.getElementById('eb_id').value = b.id || '';
        document.getElementById('db_id').value = b.id || '';
        document.getElementById('eb_name').value = b.buyer_name || '';
        document.getElementById('eb_contact').value = b.contact_person || '';
        document.getElementById('eb_email').value = b.email || '';
        document.getElementById('eb_phone').value = b.phone || '';
        document.getElementById('eb_notes').value = b.notes || '';
        document.getElementById('eb_active').value = String(parseInt(b.is_active || 0, 10) === 1 ? 1 : 0);
        document.getElementById('editBuyerModal').classList.add('open');
    }

    function closeEditBuyer() {
        document.getElementById('editBuyerModal').classList.remove('open');
    }

    function openAssignCustomers(buyerId, buyerName) {
        document.getElementById('assignBuyerId').value = buyerId;
        document.getElementById('assignTitle').textContent = 'Assign Customers - ' + (buyerName || 'Buyer');

        const selected = assignedMap[String(buyerId)] || assignedMap[buyerId] || [];
        const selectedMap = {};
        selected.forEach(function(v) {
            selectedMap[String(v)] = true;
        });

        document.querySelectorAll('.assign-customer-checkbox').forEach(function(cb) {
            cb.checked = !!selectedMap[String(cb.value)];
        });

        document.getElementById('assignModal').classList.add('open');
    }

    function closeAssignCustomers() {
        document.getElementById('assignModal').classList.remove('open');
    }

    document.getElementById('editBuyerModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditBuyer();
    });
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) closeAssignCustomers();
    });
</script>
<?php pwfOfficeFooter();
