<?php

/**
 * Developer Panel - User Permissions Management
 * Assign users to businesses and configure menu access
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'User Permissions';

// Ensure Gudang Nasita menu appears in Developer Permissions for target businesses.
try {
    $pdo->beginTransaction();

    $pdo->prepare("\n        INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active)\n        VALUES ('gudang_nasita', 'Gudang Nasita', 'bi-boxes', 'modules/procurement/gudang-nasita.php', 8, 1)\n        ON DUPLICATE KEY UPDATE\n            menu_name = VALUES(menu_name),\n            menu_icon = VALUES(menu_icon),\n            menu_url = VALUES(menu_url),\n            menu_order = VALUES(menu_order),\n            is_active = VALUES(is_active)\n    ")->execute();

    $pdo->prepare("\n        INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active)\n        VALUES ('staff_chat', 'Pesan ke Staff', 'bi-chat-dots', '#', 98, 1)\n        ON DUPLICATE KEY UPDATE\n            menu_name = VALUES(menu_name),\n            menu_icon = VALUES(menu_icon),\n            menu_url = VALUES(menu_url),\n            menu_order = VALUES(menu_order),\n            is_active = VALUES(is_active)\n    ")->execute();

    $menuIdRow = $pdo->query("SELECT id FROM menu_items WHERE menu_code = 'gudang_nasita' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($menuIdRow['id'])) {
        $targetCodes = ['narayanahotel', 'benscafe', 'eatmeet', 'eaatmeet', 'gudangnasita'];
        $bizRows = $pdo->query("SELECT id, business_code, business_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $cfgStmt = $pdo->prepare("\n            INSERT INTO business_menu_config (business_id, menu_id, is_enabled)\n            VALUES (?, ?, 1)\n            ON DUPLICATE KEY UPDATE is_enabled = 1\n        ");

        foreach ($bizRows as $biz) {
            $codeNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$biz['business_code']));
            $nameNorm = strtolower((string)($biz['business_name'] ?? ''));
            $isTarget = in_array($codeNorm, $targetCodes, true)
                || strpos($nameNorm, 'narayana') !== false
                || strpos($nameNorm, 'bens') !== false
                || strpos($nameNorm, 'eat') !== false
                || strpos($nameNorm, 'gudang') !== false;
            if ($isTarget) {
                $cfgStmt->execute([(int)$biz['id'], (int)$menuIdRow['id']]);
            }
        }
    }

    $staffChatMenuIdRow = $pdo->query("SELECT id FROM menu_items WHERE menu_code = 'staff_chat' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($staffChatMenuIdRow['id'])) {
        $targetCodes = ['narayanahotel', 'benscafe', 'eatmeet', 'eaatmeet', 'gudangnasita'];
        $bizRows = $pdo->query("SELECT id, business_code, business_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $cfgStmt = $pdo->prepare("\n            INSERT INTO business_menu_config (business_id, menu_id, is_enabled)\n            VALUES (?, ?, 1)\n            ON DUPLICATE KEY UPDATE is_enabled = 1\n        ");

        foreach ($bizRows as $biz) {
            $codeNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$biz['business_code']));
            $nameNorm = strtolower((string)($biz['business_name'] ?? ''));
            $isTarget = in_array($codeNorm, $targetCodes, true)
                || strpos($nameNorm, 'narayana') !== false
                || strpos($nameNorm, 'bens') !== false
                || strpos($nameNorm, 'eat') !== false
                || strpos($nameNorm, 'gudang') !== false;
            if ($isTarget) {
                $cfgStmt->execute([(int)$biz['id'], (int)$staffChatMenuIdRow['id']]);
            }
        }
    }

    // Cafe Invoice menu (Bens Cafe only) - previously had NO menu_items row at all, so it could
    // never be configured/restricted here and always showed in the sidebar regardless of permissions.
    $pdo->prepare("\n        INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active)\n        VALUES ('cafe_invoice', 'Invoice Cafe', 'bi-receipt', 'modules/cafe-invoice/index.php', 9, 1)\n        ON DUPLICATE KEY UPDATE\n            menu_name = VALUES(menu_name),\n            menu_icon = VALUES(menu_icon),\n            menu_url = VALUES(menu_url),\n            menu_order = VALUES(menu_order),\n            is_active = VALUES(is_active)\n    ")->execute();

    $cafeInvoiceMenuIdRow = $pdo->query("SELECT id FROM menu_items WHERE menu_code = 'cafe_invoice' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($cafeInvoiceMenuIdRow['id'])) {
        $bizRows = $pdo->query("SELECT id, business_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $cfgStmt = $pdo->prepare("\n            INSERT INTO business_menu_config (business_id, menu_id, is_enabled)\n            VALUES (?, ?, 1)\n            ON DUPLICATE KEY UPDATE is_enabled = 1\n        ");

        foreach ($bizRows as $biz) {
            $nameNorm = strtolower((string)($biz['business_name'] ?? ''));
            if (strpos($nameNorm, 'bens') !== false) {
                $cfgStmt->execute([(int)$biz['id'], (int)$cafeInvoiceMenuIdRow['id']]);
            }
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$businessId = $_GET['business_id'] ?? null;
$userId = $_GET['user_id'] ?? null;
$error = '';
$success = '';

// Get all businesses for filter
$businesses = [];
try {
    $businesses = $pdo->query("SELECT id, business_code, business_name FROM businesses WHERE is_active = 1 ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Get all non-developer users
$users = [];
try {
    $users = $pdo->query("
        SELECT u.*, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_code != 'developer' AND u.is_active = 1
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Assign user to business
    if ($formAction === 'assign_user') {
        $assignUserId = (int)$_POST['user_id'];
        $assignBusinessId = (int)$_POST['business_id'];
        $selectedMenus = $_POST['menus'] ?? [];

        if ($assignUserId && $assignBusinessId) {
            try {
                $pdo->beginTransaction();

                // Check if assignment exists
                $check = $pdo->prepare("SELECT id FROM user_business_assignment WHERE user_id = ? AND business_id = ?");
                $check->execute([$assignUserId, $assignBusinessId]);
                $existingAssign = $check->fetch(PDO::FETCH_ASSOC);

                if (!$existingAssign) {
                    // Create assignment
                    $stmt = $pdo->prepare("INSERT INTO user_business_assignment (user_id, business_id) VALUES (?, ?)");
                    $stmt->execute([$assignUserId, $assignBusinessId]);
                }

                // Clear existing permissions for this user-business
                $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$assignUserId, $assignBusinessId]);

                // Get menu_code mapping
                $menuCodes = [];
                $menuQuery = $pdo->query("SELECT id, menu_code FROM menu_items");
                while ($row = $menuQuery->fetch(PDO::FETCH_ASSOC)) {
                    $menuCodes[$row['id']] = $row['menu_code'];
                }

                // Add new permissions with menu_code. Insert a row for EVERY business menu (not
                // just checked ones) so an unchecked menu is an explicit deny (can_view=0) rather
                // than "no row" - which hasPermission() treats as "never configured" = full access.
                $allBizMenuStmt = $pdo->prepare("
                    SELECT m.id FROM menu_items m
                    JOIN business_menu_config bmc ON m.id = bmc.menu_id
                    WHERE bmc.business_id = ? AND bmc.is_enabled = 1 AND m.is_active = 1
                ");
                $allBizMenuStmt->execute([$assignBusinessId]);
                $allBizMenuIds = $allBizMenuStmt->fetchAll(PDO::FETCH_COLUMN);

                $permStmt = $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($allBizMenuIds as $menuId) {
                    $menuCode = $menuCodes[$menuId] ?? '';
                    $isSelected = in_array($menuId, $selectedMenus, true) || in_array((string)$menuId, $selectedMenus, true);
                    $perm = $isSelected ? 1 : 0;
                    $permStmt->execute([$assignUserId, $assignBusinessId, $menuId, $menuCode, $perm, $perm, $perm, $perm]);
                }

                $pdo->commit();

                $auth->logAction('assign_user_business', 'user_business_assignment', null, null, [
                    'user_id' => $assignUserId,
                    'business_id' => $assignBusinessId,
                    'menus' => $selectedMenus
                ]);

                $_SESSION['success_message'] = 'User assigned and permissions updated!';
                header("Location: permissions.php?business_id=$assignBusinessId");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to assign: ' . $e->getMessage();
            }
        }
    }

    // Update permissions
    if ($formAction === 'update_permissions') {
        $permUserId = (int)$_POST['user_id'];
        $permBusinessId = (int)$_POST['business_id'];
        $permissions = $_POST['permissions'] ?? [];

        if ($permUserId && $permBusinessId) {
            try {
                $pdo->beginTransaction();

                // Clear existing permissions
                $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$permUserId, $permBusinessId]);

                // Add new permissions with granular control
                $permStmt = $pdo->prepare("
                    INSERT INTO user_menu_permissions (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                // Get menu_code mapping
                $menuCodes = [];
                $menuQuery = $pdo->query("SELECT id, menu_code FROM menu_items");
                while ($row = $menuQuery->fetch(PDO::FETCH_ASSOC)) {
                    $menuCodes[$row['id']] = $row['menu_code'];
                }

                // Iterate over ALL menus enabled for this business (not just $_POST['permissions']
                // keys) - a fully-unchecked checkbox row never appears in $_POST at all, so relying
                // on $_POST alone would skip inserting a row for menus the developer explicitly
                // wants to deny, leaving 0 rows saved = indistinguishable from "never configured"
                // (which hasPermission() treats as full access for backward compatibility).
                $bizMenuStmt = $pdo->prepare("
                    SELECT m.id FROM menu_items m
                    JOIN business_menu_config bmc ON m.id = bmc.menu_id
                    WHERE bmc.business_id = ? AND bmc.is_enabled = 1 AND m.is_active = 1
                ");
                $bizMenuStmt->execute([$permBusinessId]);
                $bizMenuIds = $bizMenuStmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($bizMenuIds as $menuId) {
                    $perms = $permissions[$menuId] ?? [];
                    $canView = isset($perms['view']) ? 1 : 0;
                    $canCreate = isset($perms['create']) ? 1 : 0;
                    $canEdit = isset($perms['edit']) ? 1 : 0;
                    $canDelete = isset($perms['delete']) ? 1 : 0;
                    $menuCode = $menuCodes[$menuId] ?? '';

                    $permStmt->execute([$permUserId, $permBusinessId, $menuId, $menuCode, $canView, $canCreate, $canEdit, $canDelete]);
                }

                $pdo->commit();

                $auth->logAction('update_permissions', 'user_menu_permissions', null, null, [
                    'user_id' => $permUserId,
                    'business_id' => $permBusinessId
                ]);

                $_SESSION['success_message'] = 'Permissions updated successfully!';
                header("Location: permissions.php?business_id=$permBusinessId&user_id=$permUserId");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to update: ' . $e->getMessage();
            }
        }
    }

    // Remove user from business
    if ($formAction === 'remove_user') {
        $removeUserId = (int)$_POST['user_id'];
        $removeBusinessId = (int)$_POST['business_id'];

        if ($removeUserId && $removeBusinessId) {
            try {
                $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id = ? AND business_id = ?")->execute([$removeUserId, $removeBusinessId]);
                $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$removeUserId, $removeBusinessId]);

                $auth->logAction('remove_user_business', 'user_business_assignment', null, null, [
                    'user_id' => $removeUserId,
                    'business_id' => $removeBusinessId
                ]);

                $_SESSION['success_message'] = 'User removed from business!';
                header("Location: permissions.php?business_id=$removeBusinessId");
                exit;
            } catch (Exception $e) {
                $error = 'Failed to remove: ' . $e->getMessage();
            }
        }
    }
}

// Get selected business details
$selectedBusiness = null;
$businessMenus = [];
$assignedUsers = [];

if ($businessId) {
    // Get business
    $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $bizStmt->execute([$businessId]);
    $selectedBusiness = $bizStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedBusiness) {
        // Get enabled menus for this business
        $menuStmt = $pdo->prepare("
            SELECT m.* FROM menu_items m
            JOIN business_menu_config bmc ON m.id = bmc.menu_id
            WHERE bmc.business_id = ? AND bmc.is_enabled = 1 AND m.is_active = 1
            ORDER BY m.menu_order
        ");
        $menuStmt->execute([$businessId]);
        $businessMenus = $menuStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get assigned users
        $userStmt = $pdo->prepare("
            SELECT u.*, r.role_name, uba.assigned_at,
                   (SELECT COUNT(*) FROM user_menu_permissions WHERE user_id = u.id AND business_id = ?) as menu_count
            FROM users u
            JOIN roles r ON u.role_id = r.id
            JOIN user_business_assignment uba ON u.id = uba.user_id
            WHERE uba.business_id = ?
            ORDER BY u.full_name
        ");
        $userStmt->execute([$businessId, $businessId]);
        $assignedUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get user permissions for detail view
$userPermissions = [];
$selectedUser = null;
if ($userId && $businessId) {
    $userStmt = $pdo->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $userStmt->execute([$userId]);
    $selectedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    $permStmt = $pdo->prepare("SELECT * FROM user_menu_permissions WHERE user_id = ? AND business_id = ?");
    $permStmt->execute([$userId, $businessId]);
    while ($row = $permStmt->fetch(PDO::FETCH_ASSOC)) {
        // Index by menu_id if available
        if ($row['menu_id']) {
            $userPermissions[$row['menu_id']] = $row;
        }
        // Also index by menu_code for backwards compatibility (when menu_id is NULL)
        if ($row['menu_code']) {
            $userPermissions['code_' . $row['menu_code']] = $row;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="mb-0">
                    <i class="bi bi-shield-lock me-2"></i>User Permissions
                    <?php if ($selectedBusiness): ?>
                        <small class="text-muted">- <?php echo htmlspecialchars($selectedBusiness['business_name']); ?></small>
                    <?php endif; ?>
                </h4>

                <!-- Business Selector -->
                <div class="d-flex gap-2 align-items-center">
                    <label class="text-muted">Select Business:</label>
                    <select class="form-select form-select-sm" style="width:250px" onchange="location.href='permissions.php?business_id='+this.value">
                        <option value="">Choose Business...</option>
                        <?php foreach ($businesses as $biz): ?>
                            <option value="<?php echo $biz['id']; ?>" <?php echo $businessId == $biz['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($biz['business_name']); ?> (<?php echo $biz['business_code']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$businessId): ?>
        <!-- No Business Selected -->
        <div class="content-card">
            <div class="text-center py-5">
                <i class="bi bi-building fs-1 text-muted mb-3 d-block"></i>
                <h5>Select a Business</h5>
                <p class="text-muted">Choose a business from the dropdown above to manage user permissions</p>
            </div>
        </div>

    <?php elseif ($userId && $selectedUser): ?>
        <!-- User Permission Detail -->
        <div class="row">
            <div class="col-lg-10">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h5>
                            <i class="bi bi-person-gear me-2"></i>
                            Edit Permissions: <?php echo htmlspecialchars($selectedUser['full_name']); ?>
                            <span class="badge bg-info ms-2"><?php echo $selectedUser['role_name']; ?></span>
                        </h5>
                        <a href="permissions.php?business_id=<?php echo $businessId; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back
                        </a>
                    </div>

                    <div class="p-4">
                        <form method="POST" action="">
                            <input type="hidden" name="form_action" value="update_permissions">
                            <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                            <input type="hidden" name="business_id" value="<?php echo $businessId; ?>">

                            <div class="alert alert-info mb-4">
                                <i class="bi bi-info-circle me-2"></i>
                                Configure granular permissions for <strong><?php echo htmlspecialchars($selectedUser['full_name']); ?></strong>
                                on <strong><?php echo htmlspecialchars($selectedBusiness['business_name']); ?></strong>
                            </div>

                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:30%">Menu</th>
                                        <th class="text-center">View</th>
                                        <th class="text-center">Create</th>
                                        <th class="text-center">Edit</th>
                                        <th class="text-center">Delete</th>
                                        <th class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPerms()">All</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPerms()">None</button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($businessMenus as $menu): ?>
                                        <?php $perm = $userPermissions[$menu['id']] ?? $userPermissions['code_' . $menu['menu_code']] ?? null; ?>
                                        <tr>
                                            <td>
                                                <i class="<?php echo htmlspecialchars($menu['menu_icon']); ?> me-2"></i>
                                                <?php echo htmlspecialchars($menu['menu_name']); ?>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input perm-check"
                                                    name="permissions[<?php echo $menu['id']; ?>][view]"
                                                    <?php echo ($perm && $perm['can_view']) ? 'checked' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input perm-check"
                                                    name="permissions[<?php echo $menu['id']; ?>][create]"
                                                    <?php echo ($perm && $perm['can_create']) ? 'checked' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input perm-check"
                                                    name="permissions[<?php echo $menu['id']; ?>][edit]"
                                                    <?php echo ($perm && $perm['can_edit']) ? 'checked' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input perm-check"
                                                    name="permissions[<?php echo $menu['id']; ?>][delete]"
                                                    <?php echo ($perm && $perm['can_delete']) ? 'checked' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="selectRow(this)">✓</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>Save Permissions
                                </button>
                                <a href="permissions.php?business_id=<?php echo $businessId; ?>" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function selectAllPerms() {
                document.querySelectorAll('.perm-check').forEach(cb => cb.checked = true);
            }

            function clearAllPerms() {
                document.querySelectorAll('.perm-check').forEach(cb => cb.checked = false);
            }

            function selectRow(btn) {
                btn.closest('tr').querySelectorAll('.perm-check').forEach(cb => cb.checked = true);
            }
        </script>

    <?php else: ?>
        <!-- Business Selected - Show Assigned Users & Add Form -->
        <div class="row">
            <!-- Assigned Users -->
            <div class="col-lg-8">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-people me-2"></i>Assigned Users</h5>
                        <span class="badge bg-primary"><?php echo count($assignedUsers); ?> users</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Menu Access</th>
                                    <th>Assigned</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($assignedUsers)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                                            No users assigned to this business yet
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($assignedUsers as $au): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($au['full_name']); ?></strong>
                                                <br><small class="text-muted">@<?php echo $au['username']; ?></small>
                                            </td>
                                            <td><span class="badge bg-secondary"><?php echo $au['role_name']; ?></span></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $au['menu_count']; ?> of <?php echo count($businessMenus); ?> menus</span>
                                            </td>
                                            <td class="text-muted small"><?php echo date('d M Y', strtotime($au['assigned_at'])); ?></td>
                                            <td>
                                                <a href="?business_id=<?php echo $businessId; ?>&user_id=<?php echo $au['id']; ?>"
                                                    class="btn btn-sm btn-outline-primary" title="Edit Permissions">
                                                    <i class="bi bi-pencil"></i> Permissions
                                                </a>
                                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Remove this user from the business?')">
                                                    <input type="hidden" name="form_action" value="remove_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $au['id']; ?>">
                                                    <input type="hidden" name="business_id" value="<?php echo $businessId; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add User Form -->
            <div class="col-lg-4">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-person-plus me-2"></i>Assign User</h5>
                    </div>

                    <div class="p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="form_action" value="assign_user">
                            <input type="hidden" name="business_id" value="<?php echo $businessId; ?>">

                            <div class="mb-3">
                                <label class="form-label">Select User</label>
                                <select class="form-select" name="user_id" required>
                                    <option value="">Choose User...</option>
                                    <?php
                                    $assignedIds = array_column($assignedUsers, 'id');
                                    foreach ($users as $u):
                                        if (!in_array($u['id'], $assignedIds)):
                                    ?>
                                            <option value="<?php echo $u['id']; ?>">
                                                <?php echo htmlspecialchars($u['full_name']); ?> (<?php echo $u['role_name']; ?>)
                                            </option>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Initial Menu Access</label>
                                <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                                    <?php foreach ($businessMenus as $menu): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="menus[]" value="<?php echo $menu['id']; ?>"
                                                id="assign_menu_<?php echo $menu['id']; ?>" checked>
                                            <label class="form-check-label" for="assign_menu_<?php echo $menu['id']; ?>">
                                                <i class="<?php echo $menu['menu_icon']; ?> me-1"></i>
                                                <?php echo htmlspecialchars($menu['menu_name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">User will have full permissions on selected menus</small>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-person-plus me-1"></i>Assign User
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Business Info -->
                <div class="content-card mt-3">
                    <div class="card-header-custom">
                        <h6><i class="bi bi-info-circle me-2"></i>Business Info</h6>
                    </div>
                    <div class="p-3">
                        <p class="mb-1"><strong>Code:</strong> <?php echo htmlspecialchars($selectedBusiness['business_code']); ?></p>
                        <p class="mb-1"><strong>Database:</strong> <code><?php echo htmlspecialchars($selectedBusiness['database_name']); ?></code></p>
                        <p class="mb-1"><strong>Type:</strong> <?php echo ucfirst($selectedBusiness['business_type']); ?></p>
                        <p class="mb-0"><strong>Enabled Menus:</strong> <?php echo count($businessMenus); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>