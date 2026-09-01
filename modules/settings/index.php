<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check permission
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Pengaturan';

// Auto-create settings table if not exists
try {
    $tableExists = $db->fetchOne("SHOW TABLES LIKE 'settings'");
    if (!$tableExists) {
        $sql = file_get_contents('../../database-settings.sql');
        $db->getConnection()->exec($sql);
        setFlashMessage('success', '✅ Settings table berhasil dibuat dengan data default!');
    }
} catch (Exception $e) {
    // Continue if table exists
}


$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Pengaturan';

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// Business feature detection (config-based)
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isCQC = $hasProjectModule; // Legacy compatibility

include '../../includes/header.php';
?>

<?php if ($isCQC): ?>
<!-- CQC SETTINGS MENU - Only 4 options -->
<style>
.cqc-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}
.cqc-settings-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s;
    cursor: pointer;
}
.cqc-settings-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    border-color: #f0b429;
}
.cqc-settings-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.875rem;
}
</style>
<div class="cqc-settings-grid">
    
    <!-- Pengaturan Perusahaan -->
    <a href="company.php" class="cqc-settings-card">
        <div style="padding: 1.25rem;">
            <div class="cqc-settings-icon" style="background: linear-gradient(135deg, rgba(240, 180, 41, 0.2), rgba(240, 180, 41, 0.05));">
                <i data-feather="building" style="width: 24px; height: 24px; color: #f0b429;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: #0d1f3c; margin-bottom: 0.4rem;">
                Pengaturan Perusahaan
            </h3>
            <p style="font-size: 0.813rem; color: #6b7280; margin: 0;">
                Edit nama, logo, alamat, kontak CQC untuk laporan dan tampilan
            </p>
        </div>
    </a>
    
    <!-- Ganti Password -->
    <a href="change-password.php" class="cqc-settings-card">
        <div style="padding: 1.25rem;">
            <div class="cqc-settings-icon" style="background: linear-gradient(135deg, rgba(13, 31, 60, 0.15), rgba(13, 31, 60, 0.05));">
                <i data-feather="lock" style="width: 24px; height: 24px; color: #0d1f3c;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: #0d1f3c; margin-bottom: 0.4rem;">
                Ganti Password
            </h3>
            <p style="font-size: 0.813rem; color: #6b7280; margin: 0;">
                Ubah password akun Anda dengan verifikasi password lama
            </p>
        </div>
    </a>
    
    <!-- Pengaturan Laporan PDF -->
    <a href="report-settings.php" class="cqc-settings-card">
        <div style="padding: 1.25rem;">
            <div class="cqc-settings-icon" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(59, 130, 246, 0.05));">
                <i data-feather="file-text" style="width: 24px; height: 24px; color: #3b82f6;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: #0d1f3c; margin-bottom: 0.4rem;">
                Pengaturan Laporan PDF
            </h3>
            <p style="font-size: 0.813rem; color: #6b7280; margin: 0;">
                Konfigurasi header laporan: logo, nama, alamat yang tampil di PDF
            </p>
        </div>
    </a>
    
    <!-- Backup & Reset Data -->
    <a href="reset.php" class="cqc-settings-card">
        <div style="padding: 1.25rem;">
            <div class="cqc-settings-icon" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05));">
                <i data-feather="database" style="width: 24px; height: 24px; color: #ef4444;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: #0d1f3c; margin-bottom: 0.4rem;">
                Reset Data
            </h3>
            <p style="font-size: 0.813rem; color: #6b7280; margin: 0;">
                Backup database, reset data buku kas CQC atau data proyek
            </p>
        </div>
    </a>
    
</div>

<?php else: ?>
<!-- REGULAR SETTINGS MENU - All options -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
    
    <!-- Company Settings -->
    <a href="company.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(99, 102, 241, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="building" style="width: 24px; height: 24px; color: var(--primary-color);"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Pengaturan Perusahaan
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Edit nama, logo, alamat, kontak perusahaan untuk laporan dan tampilan
            </p>
        </div>
    </a>
    
    <!-- Divisions Management -->
    <a href="divisions.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="grid" style="width: 24px; height: 24px; color: var(--success);"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Kelola Divisi
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Tambah, edit, hapus divisi hotel (Restaurant, SPA, Laundry, dll)
            </p>
        </div>
    </a>
    
    <!-- Categories Management -->
    <a href="categories.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="tag" style="width: 24px; height: 24px; color: #f59e0b;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Kelola Kategori
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Kelola kategori transaksi per divisi (pemasukan & pengeluaran)
            </p>
        </div>
    </a>
    
    <!-- Change Password -->
    <a href="change-password.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(139, 92, 246, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="lock" style="width: 24px; height: 24px; color: var(--secondary-color);"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Ganti Password
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Ubah password akun Anda dengan verifikasi password lama
            </p>
        </div>
    </a>
    
    <!-- Display Settings -->
    <a href="display.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(236, 72, 153, 0.2), rgba(236, 72, 153, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="monitor" style="width: 24px; height: 24px; color: var(--accent-color);"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Pengaturan Tampilan
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Atur format tanggal, mata uang, timezone, tema gelap/terang dan bahasa
            </p>
        </div>
    </a>
    
    <!-- Report Settings -->
    <a href="report-settings.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="file-text" style="width: 24px; height: 24px; color: var(--danger);"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Pengaturan Laporan PDF
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Konfigurasi header laporan: logo, nama, alamat yang tampil di PDF
            </p>
        </div>
    </a>
    
    <!-- End Shift Settings -->
    <a href="end-shift.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, #f093fb 0%, #f5576c 20%); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="power" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                🌅 End Shift Configuration
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Atur nomor WhatsApp GM/Admin untuk menerima laporan End Shift otomatis
            </p>
        </div>
    </a>
    
    <!-- Backup & Reset Data -->
    <a href="reset.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(234, 179, 8, 0.2), rgba(234, 179, 8, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="database" style="width: 24px; height: 24px; color: #eab308;"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Backup & Reset Data
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Backup database, restore data, atau reset data tertentu
            </p>
        </div>
    </a>
    
    <!-- Reset Audit Log -->
    <a href="reset-audit-log.php" class="card" style="text-decoration: none; transition: all 0.3s; cursor: pointer;">
        <div style="padding: 1.25rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 0.875rem;">
                <i data-feather="trash-2" style="width: 24px; height: 24px; color: var(--danger);"></i>
            </div>
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem;">
                Reset Audit Log
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Hapus semua catatan audit log sistem untuk membersihkan data lama
            </p>
        </div>
    </a>
    
</div>
<?php endif; ?>

<!-- System Info -->
<div class="card" style="margin-top: 1.5rem; padding: 1.25rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.05));">
    <h3 style="font-size: 0.938rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
        <i data-feather="info" style="width: 18px; height: 18px; color: var(--primary-color);"></i>
        Informasi Sistem
    </h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
        <div>
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.25rem;">PHP Version</div>
            <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);"><?php echo PHP_VERSION; ?></div>
        </div>
        <div>
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.25rem;">Database</div>
            <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">MySQL <?php echo $db->query("SELECT VERSION()")->fetch()[0]; ?></div>
        </div>
        <div>
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.25rem;">Server</div>
            <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);"><?php echo $_SERVER['SERVER_SOFTWARE']; ?></div>
        </div>
        <div>
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.25rem;">Application Version</div>
            <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">1.0.0</div>
        </div>
    </div>
</div>

<script>
    feather.replace();
    
    // Add hover effect
    document.querySelectorAll('a.card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
            this.style.boxShadow = '0 12px 20px -5px rgba(0, 0, 0, 0.2)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1)';
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>
