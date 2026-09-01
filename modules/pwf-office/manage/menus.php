<?php

/**
 * PWF Management - Menu Items Management
 * Kelola menu yang tersedia di PWF po system
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$msg = '';
$msgType = 'success';

// Get PWF business ID
$pwfBiz = $masterPdo->query("SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
$pwfBizId = $pwfBiz['id'] ?? null;

// Get all menus for PWF
$stmt = $masterPdo->prepare("SELECT id, menu_name, menu_label, menu_order FROM menu_items WHERE business_id = ? ORDER BY menu_order, menu_name");
$stmt->execute([$pwfBizId]);
$menus = $stmt->fetchAll();

// Handle add menu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $menuName = trim($_POST['menu_name'] ?? '');
        $menuLabel = trim($_POST['menu_label'] ?? '');
        $menuOrder = (int)($_POST['menu_order'] ?? 0);

        if (empty($menuName) || empty($menuLabel)) {
            $msg = 'Menu name dan label harus diisi!';
            $msgType = 'danger';
        } else {
            $stmt = $masterPdo->prepare("INSERT INTO menu_items (business_id, menu_name, menu_label, menu_order) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$pwfBizId, $menuName, $menuLabel, $menuOrder])) {
                $msg = "✅ Menu '$menuLabel' berhasil ditambahkan!";
                header("Refresh:1;url={$_SERVER['REQUEST_URI']}");
            } else {
                $msg = "❌ Error: " . $stmt->errorInfo()[2];
                $msgType = 'danger';
            }
        }
    } elseif ($_POST['action'] === 'update') {
        $menuId = (int)($_POST['menu_id'] ?? 0);
        $menuLabel = trim($_POST['menu_label'] ?? '');
        $menuOrder = (int)($_POST['menu_order'] ?? 0);

        if ($menuId > 0 && !empty($menuLabel)) {
            $stmt = $masterPdo->prepare("UPDATE menu_items SET menu_label = ?, menu_order = ? WHERE id = ? AND business_id = ?");
            if ($stmt->execute([$menuLabel, $menuOrder, $menuId, $pwfBizId])) {
                $msg = "✅ Menu berhasil diupdate!";
                header("Refresh:1;url={$_SERVER['REQUEST_URI']}");
            } else {
                $msg = "❌ Error: " . $stmt->errorInfo()[2];
                $msgType = 'danger';
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $menuId = (int)($_POST['menu_id'] ?? 0);

        if ($menuId > 0) {
            // Delete related permissions first
            $masterPdo->prepare("DELETE FROM user_menu_permissions WHERE menu_id = ? AND business_id = ?")->execute([$menuId, $pwfBizId]);

            // Delete menu
            $stmt = $masterPdo->prepare("DELETE FROM menu_items WHERE id = ? AND business_id = ?");
            if ($stmt->execute([$menuId, $pwfBizId])) {
                $msg = "✅ Menu berhasil dihapus!";
                header("Refresh:1;url={$_SERVER['REQUEST_URI']}");
            } else {
                $msg = "❌ Error: " . $stmt->errorInfo()[2];
                $msgType = 'danger';
            }
        }
    }
}

// Refresh menu list after action
if (strpos($_SERVER['REQUEST_METHOD'], 'POST') === 0) {
    $stmt = $masterPdo->prepare("SELECT id, menu_name, menu_label, menu_order FROM menu_items WHERE business_id = ? ORDER BY menu_order, menu_name");
    $stmt->execute([$pwfBizId]);
    $menus = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Menu - PWF Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --gold: #B8860B;
            --gold-accent: #D4A017;
            --gold-dark: #B45309;
            --bg-light: #F5F3F0;
            --bg-hover: #FFFBF5;
        }

        * {
            font-family: Inter, system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-light);
            padding: 20px 0;
        }

        .navbar {
            background: white;
            border-bottom: 1px solid #e5e5e5;
            padding: 20px 40px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .navbar-brand {
            font-size: 20px;
            font-weight: 600;
            color: var(--gold) !important;
        }

        .btn-nav {
            background: var(--gold);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-nav:hover {
            background: var(--gold-dark);
            color: white;
        }

        .container-main {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #999;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .card {
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
        }

        .card-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .form-control {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(184, 134, 11, 0.1);
        }

        .btn-add {
            background: var(--gold);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-add:hover {
            background: var(--gold-dark);
            color: white;
        }

        .menu-item {
            background: white;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }

        .menu-item:hover {
            background: var(--bg-hover);
            border-color: var(--gold);
        }

        .menu-item-info h5 {
            margin: 0;
            color: #333;
            font-weight: 600;
        }

        .menu-item-info small {
            color: #999;
            display: block;
            margin-top: 4px;
        }

        .menu-item-actions {
            display: flex;
            gap: 8px;
        }

        .btn-edit,
        .btn-delete {
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }

        .btn-edit:hover {
            background: #1976d2;
            color: white;
        }

        .btn-delete {
            background: #ffebee;
            color: #d32f2f;
        }

        .btn-delete:hover {
            background: #d32f2f;
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #333;
        }

        .modal-buttons {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .modal-buttons button {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-cancel {
            background: #f0f0f0;
            color: #333;
        }

        .btn-save {
            background: var(--gold);
            color: white;
        }

        .btn-save:hover {
            background: var(--gold-dark);
        }
    </style>
</head>

<body>
    <div class="navbar">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="navbar-brand">📋 PWF Management</span>
            <div style="display: flex; gap: 10px;">
                <button class="btn-nav" onclick="window.location.href='index.php'">← Kembali</button>
                <button class="btn-nav" onclick="window.location.href='index.php'">🏠 Dashboard</button>
            </div>
        </div>
    </div>

    <div class="container-main">
        <h1>Kelola Menu PWF</h1>
        <p class="subtitle">Atur menu yang tersedia untuk pengaturan akses user</p>

        <div class="alert alert-info" style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 13px;">
            <strong>ℹ️ Cara Kerja:</strong> Menu yang dibuat di sini akan muncul di list permission setting.<br>
            User hanya akan melihat menu-menu di sidebar PWF jika Anda set permission "Lihat" untuk mereka di halaman <strong>Manajemen Akses</strong>.
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?php echo $msgType; ?>" role="alert">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h5 style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                    <span>➕ Tambah Menu Baru</span>
                    <button class="btn-add" onclick="showAddModal()">+ Tambah Menu</button>
                </h5>
            </div>
        </div>

        <?php if (empty($menus)): ?>
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h5>Belum ada menu</h5>
                    <p style="margin: 0; color: #aaa; font-size: 13px;">Tambahkan menu PWF di form di atas</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <h5 style="margin-bottom: 16px;">📊 Daftar Menu PWF (<?php echo count($menus); ?>)</h5>
                    <div>
                        <?php foreach ($menus as $menu): ?>
                            <div class="menu-item">
                                <div class="menu-item-info">
                                    <h5><?php echo htmlspecialchars($menu['menu_label']); ?></h5>
                                    <small>📌 <?php echo htmlspecialchars($menu['menu_name']); ?> | Urutan: <?php echo $menu['menu_order']; ?></small>
                                </div>
                                <div class="menu-item-actions">
                                    <button class="btn-edit" onclick="showEditModal(<?php echo $menu['id']; ?>, '<?php echo htmlspecialchars(addslashes($menu['menu_label'])); ?>', <?php echo $menu['menu_order']; ?>)">✏️ Edit</button>
                                    <button class="btn-delete" onclick="deleteMenu(<?php echo $menu['id']; ?>)">🗑️ Hapus</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Menu Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">➕ Tambah Menu Baru</div>
            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label class="form-label">Nama Menu (untuk internal)</label>
                    <input type="text" name="menu_name" class="form-control" placeholder="mis: dashboard, orders" required>
                    <small style="display: block; margin-top: 6px; color: #999;">
                        💡 Gunakan salah satu: <strong>dashboard, orders, progress, shipping, rekap-order, customers, craftsmen, containers, settings, manage</strong><br>
                        Menu name harus sesuai agar permission system berfungsi!
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Label Menu (ditampilkan di UI)</label>
                    <input type="text" name="menu_label" class="form-control" placeholder="mis: Dashboard, Pesanan" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Urutan Menu</label>
                    <input type="number" name="menu_order" class="form-control" value="0">
                </div>

                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeAddModal()">Batal</button>
                    <button type="submit" class="btn-save">💾 Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Menu Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">✏️ Edit Menu</div>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="menu_id" id="editMenuId">

                <div class="form-group">
                    <label class="form-label">Label Menu</label>
                    <input type="text" name="menu_label" id="editMenuLabel" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Urutan Menu</label>
                    <input type="number" name="menu_order" id="editMenuOrder" class="form-control">
                </div>

                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Batal</button>
                    <button type="submit" class="btn-save">💾 Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">🗑️ Hapus Menu?</div>
            <p style="margin-bottom: 16px; color: #666;">Menu dan semua permission yang terkait akan dihapus.</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="menu_id" id="deleteMenuId">

                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Batal</button>
                    <button type="submit" class="btn-delete" style="background: #d32f2f; color: white; padding: 8px 16px;">🗑️ Ya, Hapus</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('addModal').classList.add('show');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('show');
        }

        function showEditModal(menuId, menuLabel, menuOrder) {
            document.getElementById('editMenuId').value = menuId;
            document.getElementById('editMenuLabel').value = menuLabel;
            document.getElementById('editMenuOrder').value = menuOrder;
            document.getElementById('editModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }

        function deleteMenu(menuId) {
            document.getElementById('deleteMenuId').value = menuId;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            var addModal = document.getElementById('addModal');
            var editModal = document.getElementById('editModal');
            var deleteModal = document.getElementById('deleteModal');

            if (event.target === addModal) closeAddModal();
            if (event.target === editModal) closeEditModal();
            if (event.target === deleteModal) closeDeleteModal();
        }
    </script>
</body>

</html>