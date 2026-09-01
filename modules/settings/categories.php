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
$pageTitle = 'Kelola Kategori';

// Handle actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'add') {
            $data = [
                'category_name' => $_POST['category_name'],
                'category_type' => $_POST['category_type'],
                'division_id' => !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null,
                'description' => $_POST['description'] ?? '',
                'is_active' => 1
            ];
            $db->insert('categories', $data);
            setFlashMessage('success', 'Kategori berhasil ditambahkan!');
        } elseif ($action === 'edit' && $id > 0) {
            $data = [
                'category_name' => $_POST['category_name'],
                'category_type' => $_POST['category_type'],
                'division_id' => !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null,
                'description' => $_POST['description'] ?? ''
            ];
            $db->update('categories', $data, 'id = :id', ['id' => $id]);
            setFlashMessage('success', 'Kategori berhasil diupdate!');
        }
        header('Location: categories.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Handle delete
if ($action === 'delete' && $id > 0) {
    try {
        $db->delete('categories', 'id = :id', ['id' => $id]);
        setFlashMessage('success', 'Kategori berhasil dihapus!');
        header('Location: categories.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Tidak dapat menghapus kategori yang masih memiliki transaksi!');
        header('Location: categories.php');
        exit;
    }
}

// Get category for edit
$editCategory = null;
if ($action === 'edit' && $id > 0) {
    $editCategory = $db->fetchOne("SELECT * FROM categories WHERE id = ?", [$id]);
}

// Get all categories with division name
$categories = $db->fetchAll("
    SELECT c.*, d.division_name 
    FROM categories c 
    LEFT JOIN divisions d ON c.division_id = d.id 
    ORDER BY c.category_type, c.category_name
");

// Group categories by transaction type
$groupedCategories = [
    'income' => [],
    'expense' => []
];
foreach ($categories as $cat) {
    $groupedCategories[$cat['category_type']][] = $cat;
}

include '../../includes/header.php';
?>

<?php
// Get divisions for dropdown
$catDivisions = $db->fetchAll("SELECT id, division_name FROM divisions WHERE is_active = 1 ORDER BY division_name");
?>

<div style="max-width: 1400px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
        <button onclick="toggleForm()" class="btn btn-primary btn-sm" id="addBtn">
            <i data-feather="plus" style="width: 14px; height: 14px;"></i> Tambah Kategori
        </button>
    </div>

    <!-- Add/Edit Form -->
    <div class="card" id="categoryForm" style="margin-bottom: 1rem; display: <?php echo $action === 'add' || $action === 'edit' ? 'block' : 'none'; ?>;">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                <?php echo $action === 'edit' ? 'Edit Kategori' : 'Tambah Kategori Baru'; ?>
            </h3>
        </div>
        <form method="POST" action="?action=<?php echo $action === 'edit' ? 'edit&id=' . $id : 'add'; ?>" style="padding: 1rem;">
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 2fr; gap: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Nama Kategori *</label>
                    <input type="text" name="category_name" class="form-control" 
                           value="<?php echo htmlspecialchars($editCategory['category_name'] ?? ''); ?>" 
                           placeholder="Room Service, F&B, Laundry..." required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Tipe Transaksi *</label>
                    <select name="category_type" class="form-control" required>
                        <option value="income" <?php echo ($editCategory['category_type'] ?? '') === 'income' ? 'selected' : ''; ?>>Income</option>
                        <option value="expense" <?php echo ($editCategory['category_type'] ?? '') === 'expense' ? 'selected' : ''; ?>>Expense</option>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Divisi</label>
                    <select name="division_id" class="form-control">
                        <option value="">-- Tanpa Divisi --</option>
                        <?php foreach ($catDivisions as $dv): ?>
                        <option value="<?php echo $dv['id']; ?>" <?php echo ($editCategory['division_id'] ?? '') == $dv['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dv['division_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Deskripsi</label>
                    <input type="text" name="description" class="form-control" 
                           value="<?php echo htmlspecialchars($editCategory['description'] ?? ''); ?>" 
                           placeholder="Catatan tambahan...">
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem; padding-top: 0.75rem; border-top: 1px solid var(--bg-tertiary);">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan
                </button>
                <button type="button" onclick="toggleForm()" class="btn btn-secondary btn-sm">Batal</button>
            </div>
        </form>
    </div>

    <!-- Categories List Grouped by Transaction Type -->
    <?php foreach ($groupedCategories as $type => $categories_list): ?>
        <?php if (!empty($categories_list)): ?>
        <div class="card" style="margin-bottom: 1rem;">
            <div style="padding: 0.875rem; border-bottom: 1px solid var(--bg-tertiary); background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-tertiary) 100%);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                        <?php if ($type === 'income'): ?>
                            <span class="badge badge-income">Income</span>
                        <?php else: ?>
                            <span class="badge badge-expense">Expense</span>
                        <?php endif; ?>
                    </h3>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <?php echo count($categories_list); ?> kategori
                    </span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama Kategori</th>
                            <th>Divisi</th>
                            <th>Deskripsi</th>
                            <th>Status</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories_list as $cat): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                <td style="font-size: 0.875rem;"><span style="color:var(--primary-color);font-weight:500"><?php echo htmlspecialchars($cat['division_name'] ?? '-'); ?></span></td>
                                <td style="color: var(--text-muted); font-size: 0.875rem;"><?php echo htmlspecialchars($cat['description'] ?: '-'); ?></td>
                                <td>
                                    <span class="badge" style="background: <?php echo $cat['is_active'] ? 'rgba(16, 185, 129, 0.15)' : 'rgba(148, 163, 184, 0.15)'; ?>; color: <?php echo $cat['is_active'] ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                                        <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                        <a href="?action=edit&id=<?php echo $cat['id']; ?>" class="btn btn-sm" style="padding: 0.35rem 0.6rem; background: var(--bg-tertiary);">
                                            <i data-feather="edit-2" style="width: 14px; height: 14px;"></i>
                                        </a>
                                        <button onclick="confirmDelete(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['category_name']); ?>')" 
                                                class="btn btn-sm" style="padding: 0.35rem 0.6rem; background: rgba(239, 68, 68, 0.15); color: var(--danger);">
                                            <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (empty($groupedCategories)): ?>
        <div class="card">
            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                <i data-feather="tag" style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.3;"></i>
                <p style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">Belum ada kategori</p>
                <p style="font-size: 0.875rem;">Klik "Tambah Kategori" untuk menambahkan kategori baru</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    feather.replace();
    
    function toggleForm() {
        const form = document.getElementById('categoryForm');
        const btn = document.getElementById('addBtn');
        if (form.style.display === 'none') {
            form.style.display = 'block';
            btn.style.display = 'none';
        } else {
            form.style.display = 'none';
            btn.style.display = 'inline-flex';
            window.location.href = 'categories.php';
        }
    }
    
    function confirmDelete(id, name) {
        if (confirm(`Hapus kategori "${name}"?\n\nPeringatan: Kategori yang memiliki transaksi tidak dapat dihapus!`)) {
            window.location.href = `?action=delete&id=${id}`;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
