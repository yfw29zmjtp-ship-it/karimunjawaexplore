<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Kelola Divisi';

// Handle actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'add') {
            $data = [
                'division_code' => strtoupper($_POST['division_code']),
                'division_name' => $_POST['division_name'],
                'division_type' => $_POST['division_type'] ?? 'both',
                'description' => $_POST['description'] ?? '',
                'is_active' => 1
            ];
            $db->insert('divisions', $data);
            setFlashMessage('success', 'Divisi berhasil ditambahkan!');
        } elseif ($action === 'edit' && $id > 0) {
            $data = [
                'division_code' => strtoupper($_POST['division_code']),
                'division_name' => $_POST['division_name'],
                'division_type' => $_POST['division_type'] ?? 'both',
                'description' => $_POST['description'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            $db->update('divisions', $data, 'id = :id', ['id' => $id]);
            setFlashMessage('success', 'Divisi berhasil diupdate!');
        }
        header('Location: divisions.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Handle delete
if ($action === 'delete' && $id > 0) {
    try {
        $result = $db->delete('divisions', 'id = :id', ['id' => $id]);
        if ($result !== false) {
             setFlashMessage('success', 'Divisi berhasil dihapus!');
        } else {
             setFlashMessage('error', 'Tidak berhasil menghapus divisi. Kemungkinan masih ada transaksi terkait.');
        }
        header('Location: divisions.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Tidak dapat menghapus divisi yang masih memiliki transaksi!');
        header('Location: divisions.php');
        exit;
    }
}

// Handle quick activate/deactivate toggle
if ($action === 'toggle_active' && $id > 0) {
    try {
        $div = $db->fetchOne("SELECT is_active FROM divisions WHERE id = ?", [$id]);
        if ($div) {
            $newStatus = $div['is_active'] ? 0 : 1;
            $db->update('divisions', ['is_active' => $newStatus], 'id = :id', ['id' => $id]);
            setFlashMessage('success', $newStatus ? 'Divisi diaktifkan kembali!' : 'Divisi dinonaktifkan!');
        }
        header('Location: divisions.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
        header('Location: divisions.php');
        exit;
    }
}

// Get division for edit
$editDivision = null;
if ($action === 'edit' && $id > 0) {
    $editDivision = $db->fetchOne("SELECT * FROM divisions WHERE id = ?", [$id]);
}

// Get all divisions
$divisions = $db->fetchAll("SELECT * FROM divisions ORDER BY division_name");

include '../../includes/header.php';
?>

<div style="max-width: 1200px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
        <button onclick="toggleForm()" class="btn btn-primary btn-sm" id="addBtn">
            <i data-feather="plus" style="width: 14px; height: 14px;"></i> Tambah Divisi
        </button>
    </div>

    <!-- Add/Edit Form -->
    <div class="card" id="divisionForm" style="margin-bottom: 1rem; display: <?php echo $action === 'add' || $action === 'edit' ? 'block' : 'none'; ?>;">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                <?php echo $action === 'edit' ? 'Edit Divisi' : 'Tambah Divisi Baru'; ?>
            </h3>
        </div>
        <form method="POST" action="?action=<?php echo $action === 'edit' ? 'edit&id=' . $id : 'add'; ?>" style="padding: 1rem;">
            <div style="display: grid; grid-template-columns: 1fr 3fr 1fr; gap: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Kode *</label>
                    <input type="text" name="division_code" class="form-control" 
                           value="<?php echo htmlspecialchars($editDivision['division_code'] ?? ''); ?>" 
                           placeholder="FO" maxlength="20" style="text-transform: uppercase;" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Nama Divisi *</label>
                    <input type="text" name="division_name" class="form-control" 
                           value="<?php echo htmlspecialchars($editDivision['division_name'] ?? ''); ?>" 
                           placeholder="Front Office" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Tipe</label>
                    <select name="division_type" class="form-control">
                        <option value="both" <?php echo ($editDivision['division_type'] ?? 'both') === 'both' ? 'selected' : ''; ?>>Both</option>
                        <option value="income" <?php echo ($editDivision['division_type'] ?? '') === 'income' ? 'selected' : ''; ?>>Income</option>
                        <option value="expense" <?php echo ($editDivision['division_type'] ?? '') === 'expense' ? 'selected' : ''; ?>>Expense</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Deskripsi</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Deskripsi divisi..."><?php echo $editDivision['description'] ?? ''; ?></textarea>
            </div>
            <?php if ($action === 'edit'): ?>
            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="is_active" id="is_active" value="1" <?php echo !empty($editDivision['is_active']) ? 'checked' : ''; ?> style="width: 16px; height: 16px;">
                <label for="is_active" class="form-label" style="margin: 0;">Divisi Aktif</label>
            </div>
            <?php endif; ?>
            <div style="display: flex; gap: 0.5rem; padding-top: 0.75rem; border-top: 1px solid var(--bg-tertiary);">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan
                </button>
                <button type="button" onclick="toggleForm()" class="btn btn-secondary btn-sm">Batal</button>
            </div>
        </form>
    </div>

    <!-- Divisions List -->
    <div class="card">
        <div style="padding: 0.875rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                Daftar Divisi (<?php echo count($divisions); ?>)
            </h3>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama Divisi</th>
                        <th>Tipe</th>
                        <th>Deskripsi</th>
                        <th>Status</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($divisions as $div): ?>
                        <tr>
                            <td><span style="font-weight: 700; color: var(--primary-color);"><?php echo $div['division_code']; ?></span></td>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($div['division_name']); ?></td>
                            <td>
                                <?php $dt = $div['division_type'] ?? 'both'; ?>
                                <span class="badge" style="background:<?php echo $dt==='income'?'rgba(16,185,129,.15)':($dt==='expense'?'rgba(239,68,68,.15)':'rgba(99,102,241,.15)'); ?>;color:<?php echo $dt==='income'?'#10b981':($dt==='expense'?'#ef4444':'#6366f1'); ?>;font-size:.7rem">
                                    <?php echo $dt === 'both' ? 'Income & Expense' : ucfirst($dt); ?>
                                </span>
                            </td>
                            <td style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($div['description'] ?: '-'); ?></td>
                            <td>
                                <button type="button" onclick="confirmToggle(<?php echo $div['id']; ?>, '<?php echo addslashes($div['division_name']); ?>', <?php echo $div['is_active'] ? 'true' : 'false'; ?>)"
                                        class="badge" style="cursor: pointer; border: none; background: <?php echo $div['is_active'] ? 'rgba(16, 185, 129, 0.15)' : 'rgba(148, 163, 184, 0.15)'; ?>; color: <?php echo $div['is_active'] ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                                    <?php echo $div['is_active'] ? 'Active' : 'Inactive'; ?>
                                </button>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <a href="?action=edit&id=<?php echo $div['id']; ?>" class="btn btn-sm" style="padding: 0.35rem 0.6rem; background: var(--bg-tertiary);">
                                        <i data-feather="edit-2" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <button onclick="confirmDelete(<?php echo $div['id']; ?>, '<?php echo addslashes($div['division_name']); ?>')" 
                                            class="btn btn-sm" style="padding: 0.35rem 0.6rem; background: rgba(239, 68, 68, 0.15); color: var(--danger);">
                                        <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($divisions)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                Belum ada divisi. Klik "Tambah Divisi" untuk menambahkan.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    feather.replace();
    
    function toggleForm() {
        const form = document.getElementById('divisionForm');
        const btn = document.getElementById('addBtn');
        if (form.style.display === 'none') {
            form.style.display = 'block';
            btn.style.display = 'none';
        } else {
            form.style.display = 'none';
            btn.style.display = 'inline-flex';
            window.location.href = 'divisions.php';
        }
    }
    
    function confirmDelete(id, name) {
        if (confirm(`Hapus divisi "${name}"?\n\nPeringatan: Divisi yang memiliki transaksi tidak dapat dihapus!`)) {
            window.location.href = `?action=delete&id=${id}`;
        }
    }

    function confirmToggle(id, name, isActive) {
        const action = isActive ? 'menonaktifkan' : 'mengaktifkan';
        if (confirm(`Yakin ingin ${action} divisi "${name}"?`)) {
            window.location.href = `?action=toggle_active&id=${id}`;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
