<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check if user is logged in
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Laporan';

include '../../includes/header.php';
?>

<!-- Report Menu -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
    
    <!-- Financial Daily Report - NEW -->
    <a href="financial-daily.php" class="card" style="text-decoration: none; transition: all 0.3s; border: 2px solid var(--primary-color);">
        <div style="padding: 1.5rem; position: relative;">
            <div style="position: absolute; top: 0.5rem; right: 0.5rem; background: var(--primary-color); color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.625rem; font-weight: 700;">
                BARU
            </div>
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(99, 102, 241, 0.3), rgba(99, 102, 241, 0.1)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="file-text" style="width: 28px; height: 28px; color: var(--primary-color);"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                📊 Laporan Keuangan Harian
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Laporan keuangan lengkap dengan detail income & expenses per divisi. Bisa dicetak PDF dengan header logo & alamat lengkap.
            </p>
        </div>
    </a>
    
    <!-- Daily Report -->
    <a href="daily.php" class="card" style="text-decoration: none; transition: all 0.3s;">
        <div style="padding: 1.5rem;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(99, 102, 241, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="calendar" style="width: 28px; height: 28px; color: var(--primary-color);"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                Laporan Harian
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Lihat ringkasan transaksi per hari dengan filter periode dan divisi
            </p>
        </div>
    </a>
    
    <!-- Division Report -->
    <a href="../divisions/index.php" class="card" style="text-decoration: none; transition: all 0.3s;">
        <div style="padding: 1.5rem;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="grid" style="width: 28px; height: 28px; color: var(--success);"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                Analisa Per Divisi
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Analisa detail performa setiap divisi dengan breakdown kategori
            </p>
        </div>
    </a>
    
    <!-- Monthly Report -->
    <a href="monthly.php" class="card" style="text-decoration: none; transition: all 0.3s;">
        <div style="padding: 1.5rem;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="bar-chart-2" style="width: 28px; height: 28px; color: #f59e0b;"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                Laporan Bulanan
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Ringkasan transaksi per bulan dengan perbandingan antar bulan
            </p>
        </div>
    </a>
    
    <!-- Category Report -->
    <a href="category.php" class="card" style="text-decoration: none; transition: all 0.3s;">
        <div style="padding: 1.5rem;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(139, 92, 246, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="tag" style="width: 28px; height: 28px; color: var(--secondary-color);"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                Laporan Per Kategori
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Analisa transaksi berdasarkan kategori income dan expense
            </p>
        </div>
    </a>
    
    <!-- Cash Book -->
    <a href="../cashbook/index.php" class="card" style="text-decoration: none; transition: all 0.3s;">
        <div style="padding: 1.5rem;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="book-open" style="width: 28px; height: 28px; color: var(--danger);"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                Buku Kas Besar
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Daftar lengkap semua transaksi dengan filter dan pencarian
            </p>
        </div>
    </a>
    
    <!-- Yearly Report -->
    <a href="yearly.php" class="card" style="text-decoration: none; transition: all 0.3s;">
        <div style="padding: 1.5rem;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-lg); background: linear-gradient(135deg, rgba(6, 182, 212, 0.2), rgba(6, 182, 212, 0.05)); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <i data-feather="trending-up" style="width: 28px; height: 28px; color: #06b6d4;"></i>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                Laporan Tahunan
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                Overview performa tahunan dengan trend analysis
            </p>
        </div>
    </a>
    
</div>

<!-- Quick Stats -->
<div class="card" style="margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.05));">
    <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
        💡 Tips Laporan
    </h3>
    <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 0.5rem;">
        <li style="font-size: 0.875rem; color: var(--text-secondary); padding-left: 1.5rem; position: relative;">
            <span style="position: absolute; left: 0;">✓</span>
            Gunakan <strong>Laporan Harian</strong> untuk monitoring transaksi per hari
        </li>
        <li style="font-size: 0.875rem; color: var(--text-secondary); padding-left: 1.5rem; position: relative;">
            <span style="position: absolute; left: 0;">✓</span>
            Gunakan <strong>Analisa Per Divisi</strong> untuk tracking performa divisi
        </li>
        <li style="font-size: 0.875rem; color: var(--text-secondary); padding-left: 1.5rem; position: relative;">
            <span style="position: absolute; left: 0;">✓</span>
            Semua laporan bisa di-<strong>Print</strong> dan <strong>Export ke Excel</strong>
        </li>
        <li style="font-size: 0.875rem; color: var(--text-secondary); padding-left: 1.5rem; position: relative;">
            <span style="position: absolute; left: 0;">✓</span>
            Filter data sesuai kebutuhan untuk analisa yang lebih spesifik
        </li>
    </ul>
</div>

<script>
    feather.replace();
    
    // Add hover effect
    document.querySelectorAll('a.card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
            this.style.boxShadow = '0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)';
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>
