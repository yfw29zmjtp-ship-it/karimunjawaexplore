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
$investor = new InvestorManager($db);
$project = new ProjectManager($db);

// Get data
$investors = $investor->getAllInvestors();
$projects = $project->getAllProjects();

$pageTitle = 'Manajemen Investor';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="page-header">
        <h1>Manajemen Investor</h1>
        <button class="btn btn-primary">+ Tambah Investor</button>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active">Dashboard</button>
        <button class="tab-btn">Daftar Investor</button>
        <button class="tab-btn">Analitik & Laporan</button>
    </div>

    <!-- Dashboard Tab Content -->
    <div class="tab-content active">
        <!-- Summary Cards -->
        <div class="cards-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
            <div class="card-summary" style="background: #667eea; color: white; padding: 1.5rem; border-radius: 8px;">
                <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; opacity: 0.9;">Total Investor</h3>
                <p style="margin: 0; font-size: 2rem; font-weight: bold;"><?php echo count($investors) ?? 0; ?></p>
                <small style="opacity: 0.8;">Investor aktif</small>
            </div>
            
            <div class="card-summary" style="background: #48bb78; color: white; padding: 1.5rem; border-radius: 8px;">
                <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; opacity: 0.9;">Total Project</h3>
                <p style="margin: 0; font-size: 2rem; font-weight: bold;"><?php echo count($projects) ?? 0; ?></p>
                <small style="opacity: 0.8;">Project berjalan</small>
            </div>
            
            <div class="card-summary" style="background: #ed8936; color: white; padding: 1.5rem; border-radius: 8px;">
                <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; opacity: 0.9;">Total Modal</h3>
                <p style="margin: 0; font-size: 2rem; font-weight: bold;">Rp 0</p>
                <small style="opacity: 0.8;">Dana terkumpul</small>
            </div>
        </div>

        <!-- Table -->
        <div style="background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Daftar Investor</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0;">
                        <th style="text-align: left; padding: 1rem; font-weight: 600;">Nama Investor</th>
                        <th style="text-align: left; padding: 1rem; font-weight: 600;">Kontak</th>
                        <th style="text-align: left; padding: 1rem; font-weight: 600;">Status</th>
                        <th style="text-align: left; padding: 1rem; font-weight: 600;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($investors)): ?>
                    <tr>
                        <td colspan="4" style="padding: 2rem; text-align: center; color: #a0aec0;">
                            Tidak ada data investor
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($investors as $inv): ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 1rem;"><?php echo htmlspecialchars($inv['investor_name'] ?? ''); ?></td>
                            <td style="padding: 1rem;"><?php echo htmlspecialchars($inv['contact_phone'] ?? '-'); ?></td>
                            <td style="padding: 1rem;">
                                <span style="background: #c6f6d5; color: #22543d; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($inv['status'] ?? 'active'); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <a href="#" style="color: #667eea; text-decoration: none; margin-right: 1rem;">Edit</a>
                                <a href="#" style="color: #e53e3e; text-decoration: none;">Hapus</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
