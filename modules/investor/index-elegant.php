<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';
require_once $base_path . '/includes/InvestorManager.php';
require_once $base_path . '/includes/ProjectManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

// Get data
try {
    $investors = $db->query("SELECT * FROM investors ORDER BY investor_name")->fetchAll();
} catch (Exception $e) {
    $investors = [];
}

try {
    $projects = $db->query("SELECT * FROM projects ORDER BY project_name")->fetchAll();
} catch (Exception $e) {
    $projects = [];
}

$pageTitle = 'Manajemen Investor';

// Inline styles for elegant design
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
    color: #1a202c;
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

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    overflow: hidden;
    position: relative;
}

.stat-card:hover {
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
    transform: translateY(-4px);
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
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 0.5rem 0;
}

.stat-card .value {
    font-size: 2rem;
    font-weight: 700;
    color: #1a202c;
    margin: 0.5rem 0;
}

.stat-card .label {
    font-size: 0.875rem;
    color: #a0aec0;
}

.content-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid #e2e8f0;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a202c;
    margin: 0 0 1.5rem 0;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f7fafc;
}

.investors-table {
    width: 100%;
    border-collapse: collapse;
}

.investors-table thead {
    background: #f7fafc;
}

.investors-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #4a5568;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}

.investors-table td {
    padding: 1rem;
    border-bottom: 1px solid #e2e8f0;
    color: #2d3748;
}

.investors-table tbody tr {
    transition: all 0.2s ease;
}

.investors-table tbody tr:hover {
    background: #f7fafc;
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

.action-links {
    display: flex;
    gap: 1rem;
}

.action-links a {
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
}

.action-links a.edit {
    color: #667eea;
}

.action-links a.edit:hover {
    color: #764ba2;
}

.action-links a.delete {
    color: #f56565;
}

.action-links a.delete:hover {
    color: #e53e3e;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #a0aec0;
}

.empty-state svg {
    width: 60px;
    height: 60px;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state p {
    font-size: 1rem;
    margin: 0;
}

.tabs {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid #e2e8f0;
}

.tab-btn {
    padding: 1rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 600;
    color: #718096;
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
</style>
';

include '../../includes/header.php';
?>

<div class="investor-container">
    <!-- Header -->
    <div class="investor-header">
        <h1>💼 Manajemen Investor</h1>
        <button class="btn-add">+ Tambah Investor</button>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active">Dashboard</button>
        <button class="tab-btn">Daftar Investor</button>
        <button class="tab-btn">Laporan & Analitik</button>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>👥 Total Investor</h3>
            <div class="value"><?php echo count($investors) ?? 0; ?></div>
            <div class="label">Investor terdaftar</div>
        </div>

        <div class="stat-card">
            <h3>📊 Total Project</h3>
            <div class="value"><?php echo count($projects) ?? 0; ?></div>
            <div class="label">Project aktif</div>
        </div>

        <div class="stat-card">
            <h3>💰 Total Modal</h3>
            <div class="value">Rp 0</div>
            <div class="label">Dana terkumpul</div>
        </div>

        <div class="stat-card">
            <h3>📈 ROI Rata-rata</h3>
            <div class="value">0%</div>
            <div class="label">Return on investment</div>
        </div>
    </div>

    <!-- Investors Table -->
    <div class="content-section">
        <h2 class="section-title">Daftar Investor</h2>
        
        <?php if (empty($investors)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <p>Belum ada data investor</p>
        </div>
        <?php else: ?>
        <table class="investors-table">
            <thead>
                <tr>
                    <th>Nama Investor</th>
                    <th>Email</th>
                    <th>Kontak</th>
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
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($inv['status'] ?? 'active'); ?>">
                            <?php echo ucfirst(htmlspecialchars($inv['status'] ?? 'Active')); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="#" class="edit">✏️ Edit</a>
                            <a href="#" class="delete">🗑️ Hapus</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Projects Section -->
    <div class="content-section" style="margin-top: 2rem;">
        <h2 class="section-title">Daftar Project</h2>
        
        <?php if (empty($projects)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <p>Belum ada data project</p>
        </div>
        <?php else: ?>
        <table class="investors-table">
            <thead>
                <tr>
                    <th>Nama Project</th>
                    <th>Lokasi</th>
                    <th>Status</th>
                    <th>Tanggal Mulai</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($proj['project_name'] ?? $proj['name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($proj['location'] ?? '-'); ?></td>
                    <td>
                        <span class="status-badge status-active">
                            <?php echo ucfirst(htmlspecialchars($proj['status'] ?? 'Active')); ?>
                        </span>
                    </td>
                    <td><?php echo $proj['start_date'] ?? '-'; ?></td>
                    <td>
                        <div class="action-links">
                            <a href="#" class="edit">✏️ Edit</a>
                            <a href="#" class="delete">🗑️ Hapus</a>
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
