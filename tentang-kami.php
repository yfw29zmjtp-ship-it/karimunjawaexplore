<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

$pageTitle = 'Tentang Kami';
$activeNav = 'tentang';
require __DIR__ . '/includes/website-header.php';

$weAboutP1 = sunseaSetting($pdo, 'website_about_p1', '') ?: ($weCompanyName . ' adalah penyedia jasa tour & travel yang berfokus pada wisata Kepulauan Karimunjawa, Jepara. Kami melayani open trip maupun private trip, lengkap dengan penginapan, transport laut/darat, island hopping, guide lokal berpengalaman, hingga dokumentasi perjalanan.');
$weAboutP2 = sunseaSetting($pdo, 'website_about_p2', '') ?: 'Dengan sistem manajemen operasional yang rapi, setiap booking dikelola secara transparan mulai dari penawaran harga, konfirmasi jadwal, hingga penagihan — sehingga tamu dapat fokus menikmati liburan tanpa khawatir soal detail teknis.';
$weAboutVisi = sunseaSetting($pdo, 'website_about_visi', 'Menjadi mitra wisata terpercaya bagi setiap tamu yang berkunjung ke Karimunjawa.');
$weAboutMisi = sunseaSetting($pdo, 'website_about_misi', 'Memberikan pelayanan aman, nyaman, dan harga yang transparan untuk semua tamu.');
$weAboutNilai = sunseaSetting($pdo, 'website_about_nilai', 'Kejujuran, keramahan, dan tanggung jawab dalam setiap perjalanan.');
?>

<section class="we-section">
    <div class="we-container" style="max-width:820px;">
        <div class="we-section-title" style="text-align:left;">
            <h2>Tentang <?php echo htmlspecialchars($weCompanyName); ?></h2>
        </div>

        <p style="line-height:1.9;color:var(--we-text);"><?php echo nl2br(htmlspecialchars($weAboutP1)); ?></p>

        <p style="line-height:1.9;color:var(--we-text);"><?php echo nl2br(htmlspecialchars($weAboutP2)); ?></p>

        <div class="we-grid" style="margin-top:34px;">
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:30px;">🎯</div>
                    <div class="we-card-title">Visi</div>
                    <div class="we-card-meta"><?php echo nl2br(htmlspecialchars($weAboutVisi)); ?></div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:30px;">🤝</div>
                    <div class="we-card-title">Misi</div>
                    <div class="we-card-meta"><?php echo nl2br(htmlspecialchars($weAboutMisi)); ?></div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:30px;">⭐</div>
                    <div class="we-card-title">Nilai</div>
                    <div class="we-card-meta"><?php echo nl2br(htmlspecialchars($weAboutNilai)); ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>