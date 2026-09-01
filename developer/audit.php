<?php
/**
 * Developer Panel - Audit Logs
 * View system activity logs with active users
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Audit Logs';

// Filters
$filterAction = $_GET['action_type'] ?? '';
$filterUser = $_GET['user_id'] ?? '';
$filterDate = $_GET['date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get active users (logged in within last 30 minutes)
$activeUsers = [];
try {
    $activeUsers = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.email, r.role_name, u.last_login,
               (SELECT MAX(created_at) FROM audit_logs WHERE user_id = u.id) as last_activity
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.last_login IS NOT NULL 
        AND u.last_login >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND u.is_active = 1
        ORDER BY u.last_login DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: just get recently active users from audit_logs
    try {
        $activeUsers = $pdo->query("
            SELECT DISTINCT u.id, u.username, u.full_name, u.email, r.role_name,
                   u.last_login, MAX(a.created_at) as last_activity
            FROM audit_logs a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            GROUP BY u.id
            ORDER BY last_activity DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

// Get unique actions for filter
$actions = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT action_type FROM audit_logs WHERE action_type IS NOT NULL AND action_type != '' ORDER BY action_type");
    $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Get users for filter
$users = [];
try {
    $users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Build query
$where = [];
$params = [];

if ($filterAction) {
    $where[] = "a.action_type = ?";
    $params[] = $filterAction;
}
if ($filterUser) {
    $where[] = "a.user_id = ?";
    $params[] = $filterUser;
}
if ($filterDate) {
    $where[] = "DATE(a.created_at) = ?";
    $params[] = $filterDate;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countSql = "SELECT COUNT(*) FROM audit_logs a $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Get logs
$logs = [];
try {
    $sql = "
        SELECT a.*, u.full_name, u.username
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $whereClause
        ORDER BY a.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.json-data {
    max-width: 300px;
    max-height: 100px;
    overflow: auto;
    font-size: 11px;
    background: #1a1a2e;
    padding: 5px;
    border-radius: 4px;
}
.log-action {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.log-action.create { background: rgba(25, 135, 84, 0.2); color: #4ade80; }
.log-action.update { background: rgba(13, 110, 253, 0.2); color: #60a5fa; }
.log-action.delete { background: rgba(220, 53, 69, 0.2); color: #f87171; }
.log-action.login { background: rgba(111, 66, 193, 0.2); color: #a78bfa; }
.log-action.logout { background: rgba(108, 117, 125, 0.2); color: #9ca3af; }
.log-action.default { background: rgba(108, 117, 125, 0.2); color: #9ca3af; }

.active-user-card {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(59, 130, 246, 0.1));
    border: 1px solid rgba(16, 185, 129, 0.3);
    border-radius: 12px;
    padding: 15px;
    text-align: center;
    transition: all 0.3s;
}
.active-user-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.2);
}
.active-user-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #3b82f6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 700;
    color: white;
    margin: 0 auto 10px;
}
.online-indicator {
    width: 12px;
    height: 12px;
    background: #10b981;
    border-radius: 50%;
    display: inline-block;
    animation: pulse 2s infinite;
    margin-right: 5px;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.stat-card {
    background: rgba(255,255,255,0.05);
    border-radius: 10px;
    padding: 15px;
    text-align: center;
}
.stat-card .number {
    font-size: 28px;
    font-weight: 700;
    color: #00d4ff;
}
.stat-card .label {
    font-size: 12px;
    color: #9ca3af;
    margin-top: 5px;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>Audit Logs & Active Users</h4>
                <div>
                    <span class="badge bg-success me-2"><span class="online-indicator"></span><?php echo count($activeUsers); ?> Online</span>
                    <span class="badge bg-primary"><?php echo number_format($totalLogs); ?> records</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Active Users Section -->
    <?php if (!empty($activeUsers)): ?>
    <div class="content-card mb-4">
        <div class="p-3">
            <h6 class="mb-3"><i class="bi bi-people-fill me-2 text-success"></i>Users Online (Last 30 Minutes)</h6>
            <div class="row g-3">
                <?php foreach ($activeUsers as $activeUser): ?>
                <div class="col-md-3 col-sm-6">
                    <div class="active-user-card">
                        <div class="active-user-avatar">
                            <?php echo strtoupper(substr($activeUser['full_name'] ?? $activeUser['username'], 0, 1)); ?>
                        </div>
                        <div class="fw-bold"><?php echo htmlspecialchars($activeUser['full_name'] ?? $activeUser['username']); ?></div>
                        <small class="text-muted">@<?php echo htmlspecialchars($activeUser['username']); ?></small>
                        <div class="mt-2">
                            <span class="badge bg-primary"><?php echo htmlspecialchars($activeUser['role_name'] ?? 'User'); ?></span>
                        </div>
                        <div class="mt-2 small text-muted">
                            <i class="bi bi-clock me-1"></i>
                            <?php 
                            $lastTime = $activeUser['last_activity'] ?? $activeUser['last_login'];
                            if ($lastTime) {
                                $diff = time() - strtotime($lastTime);
                                if ($diff < 60) echo 'Just now';
                                elseif ($diff < 3600) echo floor($diff/60) . ' min ago';
                                else echo date('H:i', strtotime($lastTime));
                            } else {
                                echo 'Active';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="content-card mb-4">
        <div class="p-4 text-center text-muted">
            <i class="bi bi-person-slash fs-1 d-block mb-2"></i>
            No active users in the last 30 minutes
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="content-card mb-4">
        <div class="p-3">
            <h6 class="mb-3"><i class="bi bi-funnel me-2"></i>Filter Activity Logs</h6>
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Action Type</label>
                    <select class="form-select form-select-sm" name="action_type">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $filterAction === $act ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($act); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">User</label>
                    <select class="form-select form-select-sm" name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Date</label>
                    <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="audit.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Logs Table -->
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>IP Address</th>
                        <th>New Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-journal fs-1 d-block mb-2"></i>
                            No audit logs found
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <?php 
                        $actionClass = 'default';
                        $actionType = $log['action_type'] ?? '';
                        if ($actionType && strpos($actionType, 'create') !== false) $actionClass = 'create';
                        elseif ($actionType && strpos($actionType, 'update') !== false) $actionClass = 'update';
                        elseif ($actionType && strpos($actionType, 'delete') !== false) $actionClass = 'delete';
                        elseif ($actionType && strpos($actionType, 'login') !== false) $actionClass = 'login';
                        elseif ($actionType && strpos($actionType, 'logout') !== false) $actionClass = 'logout';
                    ?>
                    <tr>
                        <td class="text-muted small text-nowrap">
                            <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                            <br><?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                        </td>
                        <td>
                            <?php if (!empty($log['full_name'])): ?>
                            <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                            <br><small class="text-muted">@<?php echo htmlspecialchars($log['username'] ?? ''); ?></small>
                            <?php else: ?>
                            <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="log-action <?php echo $actionClass; ?>">
                                <?php echo htmlspecialchars($actionType ?: 'unknown'); ?>
                            </span>
                        </td>
                        <td><code class="small"><?php echo htmlspecialchars($log['table_name'] ?? '-'); ?></code></td>
                        <td><?php echo $log['record_id'] ?? '-'; ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                        <td>
                            <?php if (!empty($log['new_data'])): ?>
                            <div class="json-data">
                                <pre class="mb-0 text-info"><?php echo htmlspecialchars(json_encode(json_decode($log['new_data']), JSON_PRETTY_PRINT) ?: $log['new_data']); ?></pre>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="p-3 border-top">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
