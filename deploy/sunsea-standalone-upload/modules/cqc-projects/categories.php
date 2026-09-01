<?php
/**
 * CQC Projects - Category Management
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('cqc-projects')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$success = '';
$error = '';

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_category') {
        try {
            $stmt = $pdo->prepare("INSERT INTO cqc_expense_categories (category_name, category_icon, is_active) VALUES (?, ?, 1)");
            $stmt->execute([
                trim($_POST['category_name']),
                trim($_POST['category_icon'] ?? '📦')
            ]);
            $success = 'Kategori berhasil ditambahkan!';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'update_category') {
        try {
            $stmt = $pdo->prepare("UPDATE cqc_expense_categories SET category_name = ?, category_icon = ? WHERE id = ?");
            $stmt->execute([
                trim($_POST['category_name']),
                trim($_POST['category_icon'] ?? '📦'),
                $_POST['category_id']
            ]);
            $success = 'Kategori berhasil diupdate!';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'toggle_category') {
        try {
            $stmt = $pdo->prepare("UPDATE cqc_expense_categories SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
            $stmt->execute([$_POST['category_id']]);
            $success = 'Status kategori diubah!';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'delete_category') {
        try {
            // Check if category has expenses
            $check = $pdo->prepare("SELECT COUNT(*) FROM cqc_project_expenses WHERE category_id = ?");
            $check->execute([$_POST['category_id']]);
            if ($check->fetchColumn() > 0) {
                $error = 'Kategori tidak bisa dihapus karena sudah ada pengeluaran!';
            } else {
                $stmt = $pdo->prepare("DELETE FROM cqc_expense_categories WHERE id = ?");
                $stmt->execute([$_POST['category_id']]);
                $success = 'Kategori berhasil dihapus!';
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Get all categories
$categories = $pdo->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM cqc_project_expenses WHERE category_id = c.id) as expense_count,
           (SELECT COALESCE(SUM(amount), 0) FROM cqc_project_expenses WHERE category_id = c.id) as total_spent
    FROM cqc_expense_categories c
    ORDER BY c.id
")->fetchAll(PDO::FETCH_ASSOC);

// Common emoji icons for quick select
$commonIcons = ['📦', '🔧', '⚡', '🏗️', '🚚', '💡', '🔌', '📋', '👷', '🛠️', '📊', '💼', '🏛️', '🔩', '⛽', '🚜'];

include '../../includes/header.php';
?>

<style>
    .cqc-container { max-width: 900px; margin: 0 auto; padding: 20px; }
    .cqc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .cqc-header h1 { font-size: 20px; font-weight: 700; color: #0d1f3c; margin: 0; }
    .cqc-back-link { background: #f1f5f9; color: #475569; padding: 8px 14px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 12px; }
    .cqc-back-link:hover { background: #e2e8f0; }
    
    .cqc-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 20px; margin-bottom: 16px; }
    .cqc-card h3 { font-size: 14px; font-weight: 700; color: #0d1f3c; margin: 0 0 14px 0; padding-bottom: 10px; border-bottom: 2px solid #f0b429; }
    
    .cqc-alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 12px; font-weight: 500; }
    .cqc-alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .cqc-alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    
    .cqc-form-row { display: grid; grid-template-columns: 80px 1fr 120px; gap: 10px; align-items: end; margin-bottom: 14px; }
    .cqc-form-group { display: flex; flex-direction: column; gap: 4px; }
    .cqc-form-group label { font-size: 11px; font-weight: 600; color: #475569; }
    .cqc-form-group input { padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; }
    .cqc-form-group input:focus { outline: none; border-color: #f0b429; box-shadow: 0 0 0 2px rgba(240,180,41,0.15); }
    
    .cqc-btn { padding: 8px 16px; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; transition: all 0.2s; }
    .cqc-btn-primary { background: var(--cqc-accent, #f0b429); color: #0d1f3c; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1); }
    .cqc-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); }
    .cqc-btn-success { background: #10b981; color: white; }
    .cqc-btn-danger { background: #ef4444; color: white; font-size: 10px; padding: 4px 8px; }
    .cqc-btn-secondary { background: #f1f5f9; color: #475569; font-size: 10px; padding: 4px 8px; }
    
    .cqc-icon-picker { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px; background: #f9fafb; border-radius: 6px; margin-top: 8px; }
    .cqc-icon-btn { width: 32px; height: 32px; border: 1px solid #e2e8f0; border-radius: 6px; background: white; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; }
    .cqc-icon-btn:hover { border-color: #f0b429; background: #fffbeb; }
    .cqc-icon-btn.selected { border-color: #f0b429; background: #fef3c7; }
    
    .cqc-table { width: 100%; border-collapse: collapse; }
    .cqc-table thead { background: var(--cqc-bg, #f8fafc); }
    .cqc-table th { padding: 10px 12px; text-align: left; color: #0d1f3c; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 2px solid #f0b429; }
    .cqc-table td { padding: 12px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
    .cqc-table tbody tr:hover { background: #f9fafb; }
    
    .cqc-category-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #f0b429, #d97706); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .cqc-category-name { font-weight: 600; color: #0d1f3c; }
    .cqc-category-stats { font-size: 11px; color: #64748b; margin-top: 2px; }
    
    .cqc-status-badge { padding: 3px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    .cqc-status-badge.active { background: #d4edda; color: #155724; }
    .cqc-status-badge.inactive { background: #f8d7da; color: #721c24; }
    
    .cqc-actions { display: flex; gap: 6px; }
    
    @media (max-width: 768px) {
        .cqc-form-row { grid-template-columns: 1fr; }
        .cqc-table { font-size: 11px; }
        .cqc-table th, .cqc-table td { padding: 8px; }
    }
</style>

<div class="cqc-container">
    <!-- Header -->
    <div class="cqc-header">
        <h1>⚙️ Pengaturan Kategori Pengeluaran</h1>
        <a href="dashboard.php" class="cqc-back-link">← Kembali</a>
    </div>
    
    <?php if ($success): ?>
        <div class="cqc-alert success">✅ <?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="cqc-alert error">❌ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Add New Category -->
    <div class="cqc-card">
        <h3>➕ Tambah Kategori Baru</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_category">
            <div class="cqc-form-row">
                <div class="cqc-form-group">
                    <label>Icon</label>
                    <input type="text" name="category_icon" id="selectedIcon" value="📦" maxlength="4" style="text-align: center; font-size: 18px;">
                </div>
                <div class="cqc-form-group">
                    <label>Nama Kategori</label>
                    <input type="text" name="category_name" placeholder="Contoh: Material Elektrikal" required>
                </div>
                <div class="cqc-form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="cqc-btn cqc-btn-primary">+ Tambah</button>
                </div>
            </div>
            
            <div class="cqc-icon-picker">
                <?php foreach ($commonIcons as $icon): ?>
                    <button type="button" class="cqc-icon-btn" onclick="selectIcon('<?php echo $icon; ?>')"><?php echo $icon; ?></button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
    
    <!-- Category List -->
    <div class="cqc-card">
        <h3>📋 Daftar Kategori</h3>
        
        <?php if (empty($categories)): ?>
            <p style="text-align: center; color: #64748b; padding: 20px 0;">Belum ada kategori. Tambahkan kategori pertama di atas.</p>
        <?php else: ?>
            <table class="cqc-table">
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th>Total Transaksi</th>
                        <th>Total Pengeluaran</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="cqc-category-icon"><?php echo htmlspecialchars($cat['category_icon'] ?? '📦'); ?></div>
                                    <div>
                                        <div class="cqc-category-name"><?php echo htmlspecialchars($cat['category_name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo number_format($cat['expense_count']); ?> transaksi</td>
                            <td style="font-weight: 600; color: #0d1f3c;">Rp <?php echo number_format($cat['total_spent'], 0); ?></td>
                            <td>
                                <span class="cqc-status-badge <?php echo $cat['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $cat['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="cqc-actions">
                                    <button type="button" class="cqc-btn cqc-btn-secondary" onclick="editCategory(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['category_name']); ?>', '<?php echo htmlspecialchars($cat['category_icon'] ?? '📦'); ?>')">✏️ Edit</button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_category">
                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                        <button type="submit" class="cqc-btn cqc-btn-secondary"><?php echo $cat['is_active'] ? '🚫' : '✅'; ?></button>
                                    </form>
                                    <?php if ($cat['expense_count'] == 0): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus kategori ini?');">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                        <button type="submit" class="cqc-btn cqc-btn-danger">🗑️</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 9999; padding: 20px;">
    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 400px; margin: 100px auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
        <h3 style="margin: 0 0 16px 0; color: #0d1f3c; font-size: 16px; border-bottom: 2px solid #f0b429; padding-bottom: 10px;">✏️ Edit Kategori</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="category_id" id="edit_category_id">
            
            <div class="cqc-form-group" style="margin-bottom: 12px;">
                <label>Icon</label>
                <input type="text" name="category_icon" id="edit_category_icon" maxlength="4" style="text-align: center; font-size: 18px; width: 60px;">
            </div>
            
            <div class="cqc-form-group" style="margin-bottom: 16px;">
                <label>Nama Kategori</label>
                <input type="text" name="category_name" id="edit_category_name" required style="width: 100%;">
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="cqc-btn cqc-btn-primary" style="flex: 1;">💾 Simpan</button>
                <button type="button" class="cqc-btn cqc-btn-secondary" onclick="closeEditModal()" style="flex: 1;">Batal</button>
            </div>
        </form>
    </div>
</div>

<script>
function selectIcon(icon) {
    document.getElementById('selectedIcon').value = icon;
    document.querySelectorAll('.cqc-icon-btn').forEach(btn => btn.classList.remove('selected'));
    event.target.classList.add('selected');
}

function editCategory(id, name, icon) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('edit_category_icon').value = icon;
    document.getElementById('editModal').style.display = 'flex';
    document.getElementById('editModal').style.alignItems = 'center';
    document.getElementById('editModal').style.justifyContent = 'center';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
