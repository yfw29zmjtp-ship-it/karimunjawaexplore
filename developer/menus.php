<?php
/**
 * Developer Panel - Menu Items Management
 * Configure system menus/modules
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Menu Configuration';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Common Bootstrap icons for menus
$commonIcons = [
    'bi bi-house-door', 'bi bi-speedometer2', 'bi bi-cash-stack', 'bi bi-book',
    'bi bi-cart', 'bi bi-bag', 'bi bi-people', 'bi bi-person', 'bi bi-gear',
    'bi bi-sliders', 'bi bi-graph-up', 'bi bi-bar-chart', 'bi bi-pie-chart',
    'bi bi-calendar', 'bi bi-clock', 'bi bi-file-text', 'bi bi-folder',
    'bi bi-building', 'bi bi-door-open', 'bi bi-cup-hot', 'bi bi-calculator',
    'bi bi-receipt', 'bi bi-wallet', 'bi bi-bank', 'bi bi-credit-card',
    'bi bi-box-seam', 'bi bi-truck', 'bi bi-star', 'bi bi-bell'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    if ($formAction === 'create' || $formAction === 'update') {
        $menuCode = strtolower(trim($_POST['menu_code'] ?? ''));
        $menuName = trim($_POST['menu_name'] ?? '');
        $menuUrl = trim($_POST['menu_url'] ?? '');
        $menuIcon = trim($_POST['menu_icon'] ?? 'bi bi-circle');
        $menuOrder = (int)($_POST['menu_order'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        if (empty($menuCode) || empty($menuName)) {
            $error = 'Please fill all required fields';
        } else {
            try {
                if ($formAction === 'create') {
                    // Check duplicate
                    $check = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE menu_code = ?");
                    $check->execute([$menuCode]);
                    if ($check->fetchColumn() > 0) {
                        $error = 'Menu code already exists';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO menu_items (menu_code, menu_name, menu_url, menu_icon, menu_order, description, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$menuCode, $menuName, $menuUrl, $menuIcon, $menuOrder, $description, $isActive]);
                        
                        $auth->logAction('create_menu', 'menu_items', $pdo->lastInsertId(), null, ['name' => $menuName]);
                        $_SESSION['success_message'] = 'Menu item created successfully!';
                        header('Location: menus.php');
                        exit;
                    }
                } else {
                    // Update
                    $updateId = (int)$_POST['menu_id'];
                    
                    $stmt = $pdo->prepare("
                        UPDATE menu_items SET menu_code = ?, menu_name = ?, menu_url = ?, menu_icon = ?, menu_order = ?, description = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$menuCode, $menuName, $menuUrl, $menuIcon, $menuOrder, $description, $isActive, $updateId]);
                    
                    $auth->logAction('update_menu', 'menu_items', $updateId, null, ['name' => $menuName]);
                    $_SESSION['success_message'] = 'Menu item updated successfully!';
                    header('Location: menus.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // Reorder menus
    if ($formAction === 'reorder' && isset($_POST['orders'])) {
        try {
            $orders = json_decode($_POST['orders'], true);
            $stmt = $pdo->prepare("UPDATE menu_items SET menu_order = ? WHERE id = ?");
            foreach ($orders as $id => $order) {
                $stmt->execute([$order, $id]);
            }
            $_SESSION['success_message'] = 'Menu order updated!';
            header('Location: menus.php');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to reorder: ' . $e->getMessage();
        }
    }
}

// Handle delete
if ($action === 'delete' && $editId) {
    try {
        // Get menu info
        $menuStmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
        $menuStmt->execute([$editId]);
        $menuToDelete = $menuStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($menuToDelete) {
            $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$editId]);
            $auth->logAction('delete_menu', 'menu_items', $editId, $menuToDelete);
            $_SESSION['success_message'] = 'Menu item deleted successfully!';
        }
        header('Location: menus.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Failed to delete: ' . $e->getMessage();
    }
}

// Get menu for editing
$editMenu = null;
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$editId]);
    $editMenu = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all menus
$menus = [];
$parentMenus = [];
try {
    $stmt = $pdo->query("
        SELECT m.*, 
               (SELECT COUNT(*) FROM business_menu_config WHERE menu_id = m.id AND is_enabled = 1) as business_count
        FROM menu_items m
        ORDER BY m.menu_order
    ");
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Error loading menus: ' . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- Add/Edit Form -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-menu-button-wide me-2"></i>
                        <?php echo $action === 'add' ? 'Add New Menu Item' : 'Edit Menu Item'; ?>
                    </h5>
                    <a href="menus.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to List
                    </a>
                </div>
                
                <div class="p-4">
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'create' : 'update'; ?>">
                        <?php if ($editMenu): ?>
                        <input type="hidden" name="menu_id" value="<?php echo $editMenu['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menu Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="menu_code" required
                                       placeholder="e.g., dashboard, reports"
                                       value="<?php echo htmlspecialchars($editMenu['menu_code'] ?? ''); ?>">
                                <small class="text-muted">Unique identifier (lowercase, no spaces)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menu Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="menu_name" required
                                       placeholder="e.g., Dashboard, Sales Report"
                                       value="<?php echo htmlspecialchars($editMenu['menu_name'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menu URL</label>
                                <input type="text" class="form-control" name="menu_url"
                                       placeholder="e.g., /modules/dashboard/index.php"
                                       value="<?php echo htmlspecialchars($editMenu['menu_url'] ?? ''); ?>">
                                <small class="text-muted">Path relative to root (optional)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menu Order</label>
                                <input type="number" class="form-control" name="menu_order" min="0"
                                       value="<?php echo $editMenu['menu_order'] ?? 0; ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Icon</label>
                            <div class="input-group">
                                <span class="input-group-text" id="icon-preview">
                                    <i class="<?php echo htmlspecialchars($editMenu['menu_icon'] ?? 'bi bi-circle'); ?>"></i>
                                </span>
                                <input type="text" class="form-control" name="menu_icon" id="menu_icon"
                                       value="<?php echo htmlspecialchars($editMenu['menu_icon'] ?? 'bi bi-circle'); ?>"
                                       placeholder="bi bi-house-door">
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">Common icons: </small>
                                <?php foreach (array_slice($commonIcons, 0, 10) as $icon): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 icon-picker" data-icon="<?php echo $icon; ?>">
                                    <i class="<?php echo $icon; ?>"></i>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars($editMenu['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                       <?php echo ($editMenu['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active Menu</label>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?php echo $action === 'add' ? 'Create Menu' : 'Update Menu'; ?>
                            </button>
                            <a href="menus.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Menus List -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Menu Configuration</h4>
                <a href="?action=add" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add New Menu
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:50px">Order</th>
                        <th>Menu</th>
                        <th>Code</th>
                        <th>URL</th>
                        <th>Businesses</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($menus)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-menu-button fs-1 d-block mb-2"></i>
                            No menu items found. <a href="?action=add">Create one</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($menus as $menu): ?>
                    <tr>
                        <td class="text-center text-muted"><?php echo $menu['menu_order']; ?></td>
                        <td>
                            <i class="<?php echo htmlspecialchars($menu['menu_icon']); ?> me-2"></i>
                            <strong><?php echo htmlspecialchars($menu['menu_name']); ?></strong>
                        </td>
                        <td><code><?php echo htmlspecialchars($menu['menu_code']); ?></code></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($menu['menu_url'] ?: '-'); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo $menu['business_count']; ?> businesses</span>
                        </td>
                        <td>
                            <?php if ($menu['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?php echo $menu['id']; ?>" class="btn btn-sm btn-outline-primary px-2 py-1" title="Edit">
                                <i class="bi bi-pencil" style="font-size:0.8rem;"></i>
                            </a>
                            <button onclick="confirmDelete('?action=delete&id=<?php echo $menu['id']; ?>', '<?php echo addslashes($menu['menu_name']); ?>')" 
                                    class="btn btn-sm btn-outline-danger px-2 py-1" title="Delete">
                                <i class="bi bi-trash" style="font-size:0.8rem;"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Icon picker
document.querySelectorAll('.icon-picker').forEach(btn => {
    btn.addEventListener('click', function() {
        const icon = this.dataset.icon;
        document.getElementById('menu_icon').value = icon;
        document.querySelector('#icon-preview i').className = icon;
    });
});

// Update icon preview on input change
document.getElementById('menu_icon')?.addEventListener('input', function() {
    document.querySelector('#icon-preview i').className = this.value || 'bi bi-circle';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
