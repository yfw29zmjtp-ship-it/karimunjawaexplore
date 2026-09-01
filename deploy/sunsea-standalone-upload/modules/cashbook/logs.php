<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Audit Logs - View History
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

$pageTitle = 'Audit Log';
$pageSubtitle = 'Riwayat Aktivitas Penghapusan Data';

// Filtering
$filterTable = getGet('table', 'all');
$filterUser = getGet('user', 'all');
$filterDate = getGet('date', '');

// Build query with filters
$whereClauses = ["1=1"];
$params = [];

if ($filterTable !== 'all') {
    $whereClauses[] = "table_name = :table";
    $params['table'] = $filterTable;
}

if ($filterUser !== 'all') {
    $whereClauses[] = "user_id = :user";
    $params['user'] = $filterUser;
}

if (!empty($filterDate)) {
    $whereClauses[] = "DATE(created_at) = :date";
    $params['date'] = $filterDate;
}

$whereSQL = implode(' AND ', $whereClauses);

// Get audit logs
$logs = $db->fetchAll(
    "SELECT * FROM audit_logs 
    WHERE {$whereSQL}
    ORDER BY created_at DESC
    LIMIT 100",
    $params
);

// Get users for filter
$users = $db->fetchAll("SELECT id, full_name FROM users ORDER BY full_name");

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                📋 Audit Log
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Riwayat penghapusan dan perubahan data sistem</p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; font-weight: 600;">Total Logs</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--text-primary);">
                    <?php echo count($logs); ?>
                </div>
            </div>
            <div style="width: 3rem; height: 3rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;">
                <i data-feather="activity" style="width: 1.5rem; height: 1.5rem; color: white;"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; font-weight: 600;">Hari Ini</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--danger);">
                    <?php 
                    $todayCount = 0;
                    foreach ($logs as $log) {
                        if (date('Y-m-d', strtotime($log['created_at'])) === date('Y-m-d')) {
                            $todayCount++;
                        }
                    }
                    echo $todayCount;
                    ?>
                </div>
            </div>
            <div style="width: 3rem; height: 3rem; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;">
                <i data-feather="calendar" style="width: 1.5rem; height: 1.5rem; color: white;"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; font-weight: 600;">Tabel Paling Sering</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">
                    <?php 
                    $tables = [];
                    foreach ($logs as $log) {
                        $tables[$log['table_name']] = ($tables[$log['table_name']] ?? 0) + 1;
                    }
                    arsort($tables);
                    echo !empty($tables) ? array_key_first($tables) : '-';
                    ?>
                </div>
            </div>
            <div style="width: 3rem; height: 3rem; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;">
                <i data-feather="database" style="width: 1.5rem; height: 1.5rem; color: white;"></i>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card" style="margin-bottom: 1.5rem;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Tabel</label>
            <select name="table" class="form-control">
                <option value="all">Semua Tabel</option>
                <option value="cash_book" <?php echo $filterTable === 'cash_book' ? 'selected' : ''; ?>>Cash Book</option>
                <option value="purchase_orders_header" <?php echo $filterTable === 'purchase_orders_header' ? 'selected' : ''; ?>>Purchase Orders</option>
                <option value="suppliers" <?php echo $filterTable === 'suppliers' ? 'selected' : ''; ?>>Suppliers</option>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label">User</label>
            <select name="user" class="form-control">
                <option value="all">Semua User</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo $user['full_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label">Tanggal</label>
            <input type="date" name="date" class="form-control" value="<?php echo $filterDate; ?>">
        </div>

        <div style="display: flex; gap: 0.625rem;">
            <button type="submit" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem;">
                <i data-feather="filter" style="width: 16px; height: 16px;"></i> 
                <span>Filter</span>
            </button>
            <a href="logs.php" class="btn btn-secondary" style="display: flex; align-items: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem;">
                <i data-feather="x" style="width: 16px; height: 16px;"></i> 
                <span>Reset</span>
            </a>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="card">
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>User</th>
                    <th>Aksi</th>
                    <th>Tabel</th>
                    <th>Record ID</th>
                    <th>IP Address</th>
                    <th>Detail Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
                            <div>Belum ada log aktivitas</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <div style="font-weight: 600;"><?php echo date('d/m/Y', strtotime($log['created_at'])); ?></div>
                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?php echo $log['user_name']; ?></div>
                                <div style="font-size: 0.875rem; color: var(--text-muted);">ID: <?php echo $log['user_id']; ?></div>
                            </td>
                            <td>
                                <span class="badge" style="background: #ef4444; color: white; padding: 0.25rem 0.75rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 600;">
                                    <?php echo $log['action']; ?>
                                </span>
                            </td>
                            <td style="font-family: monospace; font-size: 0.875rem;"><?php echo $log['table_name']; ?></td>
                            <td style="text-align: center;"><?php echo $log['record_id']; ?></td>
                            <td style="font-family: monospace; font-size: 0.875rem; color: var(--text-muted);"><?php echo $log['ip_address']; ?></td>
                            <td>
                                <button onclick="showDetail(<?php echo $log['id']; ?>)" class="btn btn-sm btn-secondary" style="font-size: 0.75rem;">
                                    <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                                    Lihat Detail
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Detail -->
<div id="detailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; backdrop-filter: blur(4px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 0; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 700px; width: 90%; max-height: 80vh; overflow: hidden;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700;">Detail Audit Log</h3>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem; opacity: 0.9;">Informasi lengkap data yang dihapus</p>
            </div>
            <button onclick="document.getElementById('detailModal').style.display='none'" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 1.5rem; width: 32px; height: 32px; border-radius: 0.5rem; cursor: pointer;">&times;</button>
        </div>
        
        <!-- Content -->
        <div id="modalContent" style="padding: 1.5rem; overflow-y: auto; max-height: 60vh;">
            <!-- Will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
    feather.replace();
    
    const logsData = <?php echo json_encode($logs); ?>;
    
    function showDetail(logId) {
        const log = logsData.find(l => l.id == logId);
        if (!log) return;
        
        let oldData = {};
        try {
            oldData = JSON.parse(log.old_data);
        } catch (e) {
            oldData = { error: 'Data tidak dapat diparse' };
        }
        
        let html = `
            <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                <div style="display: grid; grid-template-columns: 120px 1fr; gap: 0.75rem; font-size: 0.875rem;">
                    <div style="color: #6b7280; font-weight: 600;">User:</div>
                    <div style="font-weight: 600;">${log.user_name} (ID: ${log.user_id})</div>
                    
                    <div style="color: #6b7280; font-weight: 600;">Waktu:</div>
                    <div>${new Date(log.created_at).toLocaleString('id-ID')}</div>
                    
                    <div style="color: #6b7280; font-weight: 600;">IP Address:</div>
                    <div style="font-family: monospace;">${log.ip_address}</div>
                    
                    <div style="color: #6b7280; font-weight: 600;">Browser:</div>
                    <div style="font-size: 0.75rem; color: #6b7280;">${log.user_agent.substring(0, 80)}...</div>
                </div>
            </div>
            
            <h4 style="font-size: 1rem; font-weight: 700; margin: 1.5rem 0 1rem 0; color: #1f2937;">
                <i data-feather="file-text" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                Data yang Dihapus
            </h4>
            <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 1rem; border-radius: 0.5rem;">
        `;
        
        for (const [key, value] of Object.entries(oldData)) {
            html += `
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid #fde68a; font-size: 0.875rem;">
                    <div style="font-weight: 600; color: #92400e;">${key}:</div>
                    <div style="color: #78350f;">${value}</div>
                </div>
            `;
        }
        
        html += `</div>`;
        
        document.getElementById('modalContent').innerHTML = html;
        document.getElementById('detailModal').style.display = 'block';
        feather.replace();
    }
</script>

<?php include '../../includes/footer.php'; ?>
