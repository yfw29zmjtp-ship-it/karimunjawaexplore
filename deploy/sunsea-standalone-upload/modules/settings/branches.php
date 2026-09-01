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
$pageTitle = 'Kelola Cabang';

// Handle actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'add') {
            $data = [
                'branch_code' => strtoupper($_POST['branch_code']),
                'branch_name' => $_POST['branch_name'],
                'address' => $_POST['address'],
                'city' => $_POST['city'],
                'phone' => $_POST['phone'],
                'email' => $_POST['email'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            $db->insert('branches', $data);
            setFlashMessage('success', 'Cabang berhasil ditambahkan!');
            
        } elseif ($action === 'edit' && $id > 0) {
            $data = [
                'branch_code' => strtoupper($_POST['branch_code']),
                'branch_name' => $_POST['branch_name'],
                'address' => $_POST['address'],
                'city' => $_POST['city'],
                'phone' => $_POST['phone'],
                'email' => $_POST['email'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            $db->update('branches', $data, 'id = :id', ['id' => $id]);
            setFlashMessage('success', 'Cabang berhasil diupdate!');
        }
        
        header('Location: branches.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Handle delete
if ($action === 'delete' && $id > 0) {
    try {
        $db->delete('branches', 'id = :id', ['id' => $id]);
        setFlashMessage('success', 'Cabang berhasil dihapus!');
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
    header('Location: branches.php');
    exit;
}

// Get branch for edit
$editBranch = null;
if ($action === 'edit' && $id > 0) {
    $editBranch = $db->fetchOne("SELECT * FROM branches WHERE id = ?", [$id]);
}

// Get all branches
$branches = $db->fetchAll("SELECT * FROM branches ORDER BY branch_name");

include '../../includes/header.php';
?>

<div style="max-width: 1400px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
        <button onclick="toggleForm()" class="btn btn-primary btn-sm" id="addBtn">
            <i data-feather="plus" style="width: 14px; height: 14px;"></i> Tambah Cabang
        </button>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Form Add/Edit -->
    <div class="card" id="formCard" style="display: <?php echo ($action === 'edit' && $editBranch) ? 'block' : 'none'; ?>; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.125rem; color: var(--text-primary); font-weight: 700; margin-bottom: 1.25rem;">
            <?php echo ($action === 'edit') ? '✏️ Edit Cabang' : '➕ Tambah Cabang Baru'; ?>
        </h3>

        <form method="POST" action="?action=<?php echo $action === 'edit' ? 'edit&id=' . $id : 'add'; ?>">
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Kode Cabang *</label>
                    <input type="text" name="branch_code" class="form-control" 
                           value="<?php echo $editBranch['branch_code'] ?? ''; ?>" 
                           placeholder="Contoh: HQ, CBG001" 
                           required style="text-transform: uppercase;">
                    <small style="color: var(--text-muted); font-size: 0.75rem;">Kode unik untuk cabang</small>
                </div>
                
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Nama Cabang *</label>
                    <input type="text" name="branch_name" class="form-control" 
                           value="<?php echo $editBranch['branch_name'] ?? ''; ?>" 
                           placeholder="Contoh: Kantor Pusat Jakarta" 
                           required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Alamat</label>
                <textarea name="address" class="form-control" rows="2" 
                          placeholder="Alamat lengkap cabang"><?php echo $editBranch['address'] ?? ''; ?></textarea>
            </div>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Kota</label>
                    <input type="text" name="city" class="form-control" 
                           value="<?php echo $editBranch['city'] ?? ''; ?>" 
                           placeholder="Contoh: Jakarta">
                </div>
                
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Telepon</label>
                    <input type="text" name="phone" class="form-control" 
                           value="<?php echo $editBranch['phone'] ?? ''; ?>" 
                           placeholder="021-12345678">
                </div>
                
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" 
                           value="<?php echo $editBranch['email'] ?? ''; ?>" 
                           placeholder="cabang@narayana.com">
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1" 
                           <?php echo (!$editBranch || $editBranch['is_active']) ? 'checked' : ''; ?>>
                    <span style="font-size: 0.875rem; color: var(--text-primary);">
                        <strong>Aktif</strong> - Cabang dapat digunakan dalam sistem
                    </span>
                </label>
            </div>

            <div style="display: flex; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                <button type="submit" class="btn btn-primary">
                    <i data-feather="save" style="width: 14px; height: 14px;"></i>
                    <?php echo $action === 'edit' ? 'Update Cabang' : 'Simpan Cabang'; ?>
                </button>
                <button type="button" onclick="toggleForm()" class="btn btn-secondary">
                    <i data-feather="x" style="width: 14px; height: 14px;"></i>
                    Batal
                </button>
            </div>
        </form>
    </div>

    <!-- Branches List -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0 0.5rem 0; border-bottom: 1px solid var(--bg-tertiary); margin-bottom: 1rem;">
            <h3 style="font-size: 1.125rem; color: var(--text-primary); font-weight: 700; margin: 0;">
                🏢 Daftar Cabang (<?php echo count($branches); ?>)
            </h3>
        </div>

        <?php if (empty($branches)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p style="font-size: 0.938rem;">Belum ada cabang terdaftar</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama Cabang</th>
                            <th>Kota</th>
                            <th>Kontak</th>
                            <th>Status</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td>
                                    <span style="font-weight: 700; color: var(--primary-color); font-family: monospace;">
                                        <?php echo $branch['branch_code']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">
                                        <?php echo $branch['branch_name']; ?>
                                    </div>
                                    <?php if ($branch['address']): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?php echo $branch['address']; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($branch['city']): ?>
                                        <span style="padding: 0.25rem 0.5rem; background: var(--bg-tertiary); border-radius: 4px; font-size: 0.813rem;">
                                            <i data-feather="map-pin" style="width: 12px; height: 12px;"></i>
                                            <?php echo $branch['city']; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.813rem;">
                                    <?php if ($branch['phone']): ?>
                                        <div style="margin-bottom: 0.25rem;">
                                            <i data-feather="phone" style="width: 12px; height: 12px; color: var(--text-muted);"></i>
                                            <?php echo $branch['phone']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($branch['email']): ?>
                                        <div>
                                            <i data-feather="mail" style="width: 12px; height: 12px; color: var(--text-muted);"></i>
                                            <?php echo $branch['email']; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($branch['is_active']): ?>
                                        <span class="badge badge-success">✓ Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">✗ Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                        <a href="?action=edit&id=<?php echo $branch['id']; ?>" 
                                           class="btn btn-sm btn-primary" 
                                           title="Edit">
                                            <i data-feather="edit-2" style="width: 14px; height: 14px;"></i>
                                        </a>
                                        <a href="?action=delete&id=<?php echo $branch['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Yakin ingin menghapus cabang ini?')"
                                           title="Hapus">
                                            <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    feather.replace();

    function toggleForm() {
        const form = document.getElementById('formCard');
        const addBtn = document.getElementById('addBtn');
        
        if (form.style.display === 'none') {
            form.style.display = 'block';
            addBtn.innerHTML = '<i data-feather="x" style="width: 14px; height: 14px;"></i> Tutup Form';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setTimeout(() => feather.replace(), 100);
        } else {
            form.style.display = 'none';
            addBtn.innerHTML = '<i data-feather="plus" style="width: 14px; height: 14px;"></i> Tambah Cabang';
            feather.replace();
            
            // Redirect to clear edit mode
            if (window.location.search.includes('action=edit')) {
                window.location.href = 'branches.php';
            }
        }
    }

    // Auto-show form if in edit mode
    <?php if ($action === 'edit' && $editBranch): ?>
        document.getElementById('addBtn').innerHTML = '<i data-feather="x" style="width: 14px; height: 14px;"></i> Tutup Form';
        feather.replace();
    <?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
