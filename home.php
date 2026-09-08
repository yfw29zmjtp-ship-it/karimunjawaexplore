<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

sunseaEnsurePackageItemsSchema($pdo);
$featuredPackages = $pdo->query(
    "SELECT id, code, name, category, duration_days, duration_nights, base_price
     FROM trip_packages WHERE is_active = 1 ORDER BY id DESC LIMIT 3"
)->fetchAll();

$pageTitle = 'Beranda';
$activeNav = 'home';
require __DIR__ . '/includes/website-header.php';
?>

<section class="we-hero">
    <div class="we-container">
        <h1>Jelajahi Keindahan Karimunjawa Bersama Kami</h1>
        <p>Paket wisata open trip &amp; private trip, island hopping, penginapan, hingga transport laut/darat —
            kami urus, Anda tinggal menikmati liburan.</p>
        <div class="we-hero-actions">
            <a href="paket-wisata.php" class="we-btn we-btn-primary">Lihat Paket Wisata</a>
            <a href="kontak.php" class="we-btn we-btn-outline">Booking Sekarang</a>
        </div>
    </div>
</section>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Paket Wisata Pilihan</h2>
            <p>Beberapa paket favorit tamu kami di Karimunjawa</p>
        </div>

        <?php if ($featuredPackages): ?>
            <div class="we-grid">
                <?php foreach ($featuredPackages as $pkg): ?>
                    <div class="we-card">
                        <div class="we-card-img">🏝️</div>
                        <div class="we-card-body">
                            <div class="we-card-title"><?php echo htmlspecialchars($pkg['name']); ?></div>
                            <div class="we-card-meta">
                                <?php echo (int)$pkg['duration_days']; ?>H<?php echo (int)$pkg['duration_nights']; ?>M
                                &middot; <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $pkg['category']))); ?>
                            </div>
                            <div class="we-card-price"><?php echo sunseaRupiah((float)$pkg['base_price']); ?> <span style="font-size:11px;color:var(--we-muted);font-weight:400;">/ pax</span></div>
                        </div>
                        <div class="we-card-footer">
                            <a href="paket-wisata.php?id=<?php echo (int)$pkg['id']; ?>" class="we-card-btn">Lihat Detail</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="we-empty">Paket wisata akan segera hadir. Hubungi kami untuk custom trip.</div>
        <?php endif; ?>
    </div>
</section>

<section class="we-section we-section-alt">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Kenapa Pilih Kami?</h2>
        </div>
        <div class="we-grid">
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:34px;margin-bottom:10px;">🛥️</div>
                    <div class="we-card-title">Armada &amp; Guide Berpengalaman</div>
                    <div class="we-card-meta">Kapal dan pemandu lokal yang terpercaya di seluruh perairan Karimunjawa.</div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:34px;margin-bottom:10px;">💰</div>
                    <div class="we-card-title">Harga Transparan</div>
                    <div class="we-card-meta">Rincian biaya jelas — tanpa biaya tersembunyi, dengan opsi DP/cicilan.</div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:34px;margin-bottom:10px;">📞</div>
                    <div class="we-card-title">Layanan 24/7</div>
                    <div class="we-card-meta">Tim kami siap membantu perencanaan trip Anda kapan saja.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>