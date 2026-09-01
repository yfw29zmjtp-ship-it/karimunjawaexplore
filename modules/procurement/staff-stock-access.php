<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('procurement_stock')) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$currentUser = $auth->getCurrentUser();
$pageTitle = 'Akses Stock Staff Portal';

// Only expose the 3 operational businesses as grantable targets.
$transferBusinessConfigs = [
    'narayana-hotel' => __DIR__ . '/../../config/businesses/narayana-hotel.php',
    'bens-cafe'      => __DIR__ . '/../../config/businesses/bens-cafe.php',
    'eaat-meet'      => __DIR__ . '/../../config/businesses/eaat-meet.php',
];

$availableBusinesses = [];
foreach ($transferBusinessConfigs as $slug => $cfgPath) {
    if (!file_exists($cfgPath)) {
        continue;
    }
    $cfg = require $cfgPath;
    if (!empty($cfg['name'])) {
        $availableBusinesses[$slug] = (string)$cfg['name'];
    }
}

// Build a convenience directory of existing Staff Portal accounts (email + name + business)
// so the admin can pick from a list instead of typing the email from memory.
$staffDirectory = [];
$originDbNameForDirectory = Database::getCurrentDatabase();
foreach ($transferBusinessConfigs as $slug => $cfgPath) {
    if (!file_exists($cfgPath)) {
        continue;
    }
    $cfg = require $cfgPath;
    $dbName = (string)($cfg['database'] ?? '');
    if ($dbName === '') {
        continue;
    }
    try {
        $bizDb = Database::switchDatabase($dbName);
        $rows = $bizDb->fetchAll(
            "SELECT sa.email, pe.full_name
             FROM staff_accounts sa
             LEFT JOIN payroll_employees pe ON pe.id = sa.employee_id
             ORDER BY pe.full_name ASC"
        ) ?: [];
        foreach ($rows as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $staffDirectory[strtolower($email)] = [
                'email' => $email,
                'name' => trim((string)($row['full_name'] ?? '')),
                'business' => $cfg['name'] ?? $slug,
            ];
        }
    } catch (Throwable $e) {
        error_log('staff-stock-access directory scan error (' . $slug . '): ' . $e->getMessage());
    }
}
if ($originDbNameForDirectory !== '') {
    Database::switchDatabase($originDbNameForDirectory);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_grant') {
    $email = trim((string)($_POST['staff_email'] ?? ''));
    $name = trim((string)($_POST['staff_name'] ?? ''));
    $allowed = isset($_POST['allowed_businesses']) && is_array($_POST['allowed_businesses'])
        ? array_values(array_intersect($_POST['allowed_businesses'], array_keys($availableBusinesses)))
        : [];
    $canViewGudang = !empty($_POST['can_view_gudang_nasita']);
    $canReduceStock = !empty($_POST['can_reduce_stock']);
    $canCreatePo = !empty($_POST['can_create_po']);
    $canInputStockMasuk = !empty($_POST['can_input_stock_masuk']);

    if ($email === '') {
        $_SESSION['error'] = 'Email staff wajib diisi.';
    } else {
        $result = saveStaffStockAccessGrant($email, $name, $allowed, $canViewGudang, $canReduceStock, $canCreatePo, (int)($currentUser['id'] ?? 0), $canInputStockMasuk);
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'];
    }

    header('Location: staff-stock-access.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete_grant') {
    $id = (int)($_POST['grant_id'] ?? 0);
    if (deleteStaffStockAccessGrant($id)) {
        $_SESSION['success'] = 'Akses stock staff berhasil dihapus.';
    } else {
        $_SESSION['error'] = 'Gagal menghapus akses stock staff.';
    }

    header('Location: staff-stock-access.php');
    exit;
}

$grants = getAllStaffStockAccessGrants();

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text-primary); margin-bottom: 0.25rem;">Akses Stock Staff Portal</h2>
        <p style="color: var(--text-muted); margin: 0;">Beri akses staff tertentu untuk lihat stock &amp; buat PO ke Gudang Nasita dari HP masing-masing.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem; padding:0.85rem 1rem; border-radius:8px; background:#fee2e2; color:#991b1b;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1rem; padding:0.85rem 1rem; border-radius:8px; background:#dcfce7; color:#166534;"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 1.4fr; gap:1.25rem; align-items:start;">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;" id="grantFormTitle">Tambah / Update Akses</h3>
            <span id="editingBadge" style="display:none; font-size:0.75rem;"><a href="javascript:void(0)" onclick="cancelEditGrant()" style="color:var(--text-muted);">Batal Edit</a></span>
        </div>
        <form method="POST">
            <input type="hidden" name="form_action" value="save_grant">

            <div style="margin-bottom:0.85rem;">
                <label style="display:block; font-size:0.82rem; font-weight:600; margin-bottom:0.3rem;">Email Staff</label>
                <input type="email" name="staff_email" id="staffEmailInput" list="staffDirectoryList" required placeholder="contoh: dela@narayana.com" style="width:100%; padding:0.5rem 0.7rem; border:1px solid #e2e8f0; border-radius:8px;">
                <datalist id="staffDirectoryList">
                    <?php foreach ($staffDirectory as $entry): ?>
                        <option value="<?php echo htmlspecialchars($entry['email']); ?>"><?php echo htmlspecialchars($entry['name'] . ' — ' . $entry['business']); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <small style="color:var(--text-muted);">Harus sama dengan email login Staff Portal karyawan tsb.</small>
            </div>

            <div style="margin-bottom:0.85rem;">
                <label style="display:block; font-size:0.82rem; font-weight:600; margin-bottom:0.3rem;">Nama Staff (opsional, untuk tampilan)</label>
                <input type="text" name="staff_name" id="staffNameInput" placeholder="contoh: Dela" style="width:100%; padding:0.5rem 0.7rem; border:1px solid #e2e8f0; border-radius:8px;">
            </div>

            <div style="margin-bottom:0.85rem;">
                <label style="display:block; font-size:0.82rem; font-weight:600; margin-bottom:0.4rem;">Bisnis yang Boleh Dilihat</label>
                <?php foreach ($availableBusinesses as $slug => $name): ?>
                    <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; margin-bottom:0.3rem;">
                        <input type="checkbox" class="biz-checkbox" name="allowed_businesses[]" value="<?php echo htmlspecialchars($slug); ?>">
                        <?php echo htmlspecialchars($name); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:0.82rem; font-weight:600; margin-bottom:0.4rem;">Izin Tambahan</label>
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; margin-bottom:0.3rem;">
                    <input type="checkbox" id="permGudang" name="can_view_gudang_nasita" value="1"> Lihat Stock Gudang Nasita (pusat)
                </label>
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; margin-bottom:0.3rem;">
                    <input type="checkbox" id="permReduce" name="can_reduce_stock" value="1"> Bisa Kurangi Stock Harian
                </label>
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; margin-bottom:0.3rem;">
                    <input type="checkbox" id="permPo" name="can_create_po" value="1"> Bisa Buat PO ke Gudang Nasita
                </label>
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem;">
                    <input type="checkbox" id="permInputMasuk" name="can_input_stock_masuk" value="1"> Bisa Input Stock Barang Datang (Gudang Nasita)
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Simpan Akses</button>
        </form>
    </div>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Daftar Akses Staff</h3>
            <span style="font-size:0.8rem; color:var(--text-muted);"><?php echo count($grants); ?> staff</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Bisnis</th>
                        <th>Izin</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grants)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:1.5rem; color:var(--text-muted);">Belum ada staff dengan akses stock.</td>
                        </tr>
                        <?php else: foreach ($grants as $grant): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($grant['staff_name'] !== '' ? $grant['staff_name'] : $grant['staff_email']); ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($grant['staff_email']); ?></div>
                                </td>
                                <td style="font-size:0.82rem;">
                                    <?php
                                    $names = [];
                                    foreach ($grant['allowed_businesses'] as $slug) {
                                        $names[] = $availableBusinesses[$slug] ?? $slug;
                                    }
                                    echo htmlspecialchars(implode(', ', $names) ?: '-');
                                    ?>
                                </td>
                                <td style="font-size:0.78rem;">
                                    <?php if ($grant['can_view_gudang_nasita']): ?><span style="display:inline-block; background:#e0f2fe; color:#075985; padding:2px 6px; border-radius:6px; margin:1px;">Lihat Gudang</span><?php endif; ?>
                                    <?php if ($grant['can_reduce_stock']): ?><span style="display:inline-block; background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:6px; margin:1px;">Kurangi Stock</span><?php endif; ?>
                                    <?php if ($grant['can_create_po']): ?><span style="display:inline-block; background:#dcfce7; color:#166534; padding:2px 6px; border-radius:6px; margin:1px;">Buat PO</span><?php endif; ?>
                                    <?php if (!empty($grant['can_input_stock_masuk'])): ?><span style="display:inline-block; background:#ede9fe; color:#5b21b6; padding:2px 6px; border-radius:6px; margin:1px;">Input Barang Datang</span><?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-sm btn-secondary" style="padding:2px 8px; font-size:0.7rem; margin-right:4px;"
                                        onclick='editGrant(<?php echo json_encode([
                                                                "email" => $grant["staff_email"],
                                                                "name" => $grant["staff_name"],
                                                                "businesses" => $grant["allowed_businesses"],
                                                                "gudang" => (bool)$grant["can_view_gudang_nasita"],
                                                                "reduce" => (bool)$grant["can_reduce_stock"],
                                                                "po" => (bool)$grant["can_create_po"],
                                                                "inputMasuk" => (bool)($grant["can_input_stock_masuk"] ?? false),
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                                    <form method="POST" style="margin:0; display:inline;" onsubmit="return confirm('Hapus akses stock staff ini?')">
                                        <input type="hidden" name="form_action" value="delete_grant">
                                        <input type="hidden" name="grant_id" value="<?php echo (int)$grant['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 8px; font-size:0.7rem;">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    @media (max-width: 992px) {
        div[style*="grid-template-columns: 1fr 1.4fr"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<script>
    function editGrant(grant) {
        document.getElementById('staffEmailInput').value = grant.email || '';
        document.getElementById('staffNameInput').value = grant.name || '';
        document.querySelectorAll('.biz-checkbox').forEach(function(cb) {
            cb.checked = (grant.businesses || []).indexOf(cb.value) !== -1;
        });
        document.getElementById('permGudang').checked = !!grant.gudang;
        document.getElementById('permReduce').checked = !!grant.reduce;
        document.getElementById('permPo').checked = !!grant.po;
        document.getElementById('permInputMasuk').checked = !!grant.inputMasuk;

        document.getElementById('grantFormTitle').textContent = 'Edit Akses: ' + (grant.name || grant.email);
        document.getElementById('editingBadge').style.display = 'inline';
        document.getElementById('staffEmailInput').scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }

    function cancelEditGrant() {
        document.getElementById('staffEmailInput').closest('form').reset();
        document.getElementById('grantFormTitle').textContent = 'Tambah / Update Akses';
        document.getElementById('editingBadge').style.display = 'none';
    }
</script>

<?php include '../../includes/footer.php'; ?>