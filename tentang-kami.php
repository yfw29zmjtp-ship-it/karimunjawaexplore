<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

$pageTitle = 'Tentang Kami';
$activeNav = 'tentang';
require __DIR__ . '/includes/website-header.php';
?>

<section class="we-section">
    <div class="we-container" style="max-width:820px;">
        <div class="we-section-title" style="text-align:left;">
            <h2>Tentang <?php echo htmlspecialchars($weCompanyName); ?></h2>
        </div>

        <p style="line-height:1.9;color:var(--we-text);">
            <?php echo htmlspecialchars($weCompanyName); ?> adalah penyedia jasa tour &amp; travel yang berfokus pada
            wisata Kepulauan Karimunjawa, Jepara. Kami melayani open trip maupun private trip, lengkap dengan
            penginapan, transport laut/darat, island hopping, guide lokal berpengalaman, hingga dokumentasi perjalanan.
        </p>

        <p style="line-height:1.9;color:var(--we-text);">
            Dengan sistem manajemen operasional yang rapi, setiap booking dikelola secara transparan mulai dari
            penawaran harga, konfirmasi jadwal, hingga penagihan — sehingga tamu dapat fokus menikmati liburan
            tanpa khawatir soal detail teknis.
        </p>

        <div class="we-grid" style="margin-top:34px;">
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:30px;">🎯</div>
                    <div class="we-card-title">Visi</div>
                    <div class="we-card-meta">Menjadi mitra wisata terpercaya bagi setiap tamu yang berkunjung ke Karimunjawa.</div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:30px;">🤝</div>
                    <div class="we-card-title">Misi</div>
                    <div class="we-card-meta">Memberikan pelayanan aman, nyaman, dan harga yang transparan untuk semua tamu.</div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:30px;">⭐</div>
                    <div class="we-card-title">Nilai</div>
                    <div class="we-card-meta">Kejujuran, keramahan, dan tanggung jawab dalam setiap perjalanan.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>