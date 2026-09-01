<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

// Get investors
try {
    $investors = $db->query("SELECT * FROM investors ORDER BY investor_name")->fetchAll();
} catch (Exception $e) {
    $investors = [];
}

// Get projects with investor relationship
try {
    $projects = $db->query("SELECT * FROM projects ORDER BY project_name")->fetchAll();
} catch (Exception $e) {
    $projects = [];
}

// Get investor balances (accounting)
try {
    $balances = $db->query("SELECT * FROM investor_balances")->fetchAll();
    $balancesMap = [];
    foreach ($balances as $b) {
        $balancesMap[$b['investor_id']] = $b;
    }
} catch (Exception $e) {
    $balancesMap = [];
}

// Get project expenses summary
try {
    $stmt = $db->prepare("
        SELECT project_id, SUM(amount_idr) as total_expenses 
        FROM project_expenses 
        GROUP BY project_id
    ");
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $expensesMap = [];
    foreach ($expenses as $e) {
        $expensesMap[$e['project_id']] = $e['total_expenses'];
    }
} catch (Exception $e) {
    $expensesMap = [];
}

$pageTitle = 'Manajemen Investor';

// Inline styles using CSS variables
$inlineStyles = '
<style>
.investor-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

.investor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.investor-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.btn-add {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
}

.tabs {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    border-bottom: 1px solid var(--border-color);
}

.tab-btn {
    padding: 1rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.95rem;
}

.tab-btn.active {
    color: #667eea;
    border-bottom-color: #667eea;
}

.tab-btn:hover {
    color: #667eea;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    overflow: hidden;
    position: relative;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
}

.stat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #667eea, #764ba2);
}

.stat-card h3 {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 0.5rem 0;
}

.stat-card .value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0.5rem 0;
}

.stat-card .label {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.content-section {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 2rem;
    border: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 1.5rem 0;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--bg-tertiary, rgba(0, 0, 0, 0.05));
}

.data-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
}

.data-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}

.data-table tbody tr {
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--bg-tertiary, rgba(102, 126, 234, 0.05));
}

.status-badge {
    display: inline-block;
    padding: 0.375rem 0.875rem;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 600;
}

.status-active {
    background: #c6f6d5;
    color: #22543d;
}

.status-inactive {
    background: #fed7d7;
    color: #742a2a;
}

.amount {
    font-family: "Courier New", monospace;
    font-weight: 600;
    color: #667eea;
}

.action-links {
    display: flex;
    gap: 1rem;
}

.action-links a {
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
    color: #667eea;
}

.action-links a:hover {
    color: #764ba2;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--text-secondary);
}

.empty-state svg {
    width: 60px;
    height: 60px;
    margin-bottom: 1rem;
    opacity: 0.5;
    stroke: var(--text-secondary);
}

.empty-state p {
    font-size: 1rem;
    margin: 0;
}

.accounting-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.accounting-item {
    background: var(--bg-tertiary, rgba(0, 0, 0, 0.05));
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}

.accounting-item label {
    font-size: 0.875rem;
    color: var(--text-secondary);
    display: block;
    margin-bottom: 0.5rem;
}

.accounting-item .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    font-family: "Courier New", monospace;
}
</style>
';

include '../../includes/header.php';
?>

<div class="investor-container">
    <!-- Header -->
    <div class="investor-header">
        <h1>💼 Manajemen Investor & Project</h1>
        <button class="btn-add">+ Tambah Investor</button>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active">Dashboard</button>
        <button class="tab-btn">Daftar Investor</button>
        <button class="tab-btn">Daftar Project</button>
        <button class="tab-btn">Laporan Akuntansi</button>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>👥 Total Investor</h3>
            <div class="value"><?php echo count($investors) ?? 0; ?></div>
            <div class="label">Investor aktif</div>
        </div>

        <div class="stat-card">
            <h3>📊 Total Project</h3>
            <div class="value"><?php echo count($projects) ?? 0; ?></div>
            <div class="label">Project berjalan</div>
        </div>

        <div class="stat-card">
            <h3>💰 Total Modal</h3>
            <div class="value">Rp 0</div>
            <div class="label">Dana terkumpul</div>
        </div>

        <div class="stat-card">
            <h3>📈 Total Pengeluaran</h3>
            <div class="value">Rp 0</div>
            <div class="label">Semua project</div>
        </div>
    </div>

    <!-- Investors Section -->
    <div class="content-section">
        <h2 class="section-title">📋 Daftar Investor</h2>
        
        <?php if (empty($investors)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <p>Belum ada data investor</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Investor</th>
                    <th>Email</th>
                    <th>Kontak</th>
                    <th>Total Modal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($investors as $inv): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($inv['investor_name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($inv['email'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($inv['contact_phone'] ?? '-'); ?></td>
                    <td class="amount">
                        <?php 
                            $balance = $balancesMap[$inv['id']] ?? null;
                            $modalUSD = $balance['total_capital_usd'] ?? 0;
                            $modalIDR = $balance['total_capital_idr'] ?? 0;
                            echo 'Rp ' . number_format($modalIDR, 0, ',', '.');
                        ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($inv['status'] ?? 'active'); ?>">
                            <?php echo ucfirst(htmlspecialchars($inv['status'] ?? 'Active')); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="#edit">✏️ Edit</a>
                            <a href="#delete">🗑️ Hapus</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Projects Section -->
    <div class="content-section">
        <h2 class="section-title">📌 Daftar Project</h2>
        
        <?php if (empty($projects)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <p>Belum ada data project</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Project</th>
                    <th>Lokasi</th>
                    <th>Budget</th>
                    <th>Pengeluaran</th>
                    <th>Sisa Budget</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($proj['project_name'] ?? $proj['name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($proj['location'] ?? '-'); ?></td>
                    <td class="amount">
                        <?php echo 'Rp ' . number_format($proj['budget_idr'] ?? 0, 0, ',', '.'); ?>
                    </td>
                    <td class="amount">
                        <?php 
                            $expense = $expensesMap[$proj['id']] ?? 0;
                            echo 'Rp ' . number_format($expense, 0, ',', '.');
                        ?>
                    </td>
                    <td class="amount">
                        <?php 
                            $budget = $proj['budget_idr'] ?? 0;
                            $remaining = $budget - ($expensesMap[$proj['id']] ?? 0);
                            $textColor = $remaining < 0 ? 'style="color: #f56565;"' : '';
                            echo '<span ' . $textColor . '>Rp ' . number_format($remaining, 0, ',', '.') . '</span>';
                        ?>
                    </td>
                    <td>
                        <span class="status-badge status-active">
                            <?php echo ucfirst(htmlspecialchars($proj['status'] ?? 'Active')); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="#edit">✏️ Edit</a>
                            <a href="#delete">🗑️ Hapus</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
